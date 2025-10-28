<?php

/*
 * Instantiate the logging class
 */
$log = new Log();

/*
 * Open the bills CSV.
 */
$bills = file_get_contents(__DIR__ . '/bills.csv');

/*
 * Remove any white space.
 */
$bills = trim($bills);

/*
 * Also, retrieve our saved serialized array of hash data, so that we can only update or insert
 * bills that have changed, or that are new.
 */
$hash_path = __DIR__ . '/hashes/bills-' . SESSION_ID . '.md5';
if (file_exists($hash_path)) {
    $hashes = file_get_contents($hash_path);
    if ($hashes !== false) {
        $hashes = unserialize($hashes);
    } else {
        $hashes = array();
    }
} else {
    if (!file_exists(__DIR__ . '/hashes/')) {
        mkdir(__DIR__ . '/hashes');
    }
    $hashes = array();
}

/*
 * Connect to Memcached, as we may well be interacting with it during this session.
 */
$mc = null;
if (MEMCACHED_SERVER != '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

/*
 * If we encounter bills that can't be added, that's often (but not always) because there is no record
 * of those legislators in the system. Build up a list of them to provide a list of missing
 * legislators.
 */
$missing_legislators = array();

/*
 * Step through each row in the CSV, one by one.
 */
$bills = explode("\n", $bills);
unset($bills[0]);
foreach ($bills as $bill) {
    $bill = str_getcsv($bill, ',', '"');

    // Before we proceed any farther, see if this record is either new or different than last time
    // time that we examined it.
    $hash = md5(serialize($bill));
    $number = strtolower(trim($bill[0]));

    if (isset($hashes[$number]) && ($hash == $hashes[$number])) {
        continue;
    } else {
        $hashes[$number] = $hash;
        if (!isset($hashes[$number])) {
            $log->put('Adding ' . strtoupper($number) . '.', 4);
        } else {
            $log->put('Updating ' . strtoupper($number) . '.', 2);
        }
    }

    /*
     * Clean up the bill CSV
     */
    $import = new Import($log);
    $bill = $import->prepare_bill($bill);

    // Check to see if the bill is already in the database.
    $sql = 'SELECT id
			FROM bills
			WHERE number="' . $bill['number'] . '" AND
            session_id=' . SESSION_ID;
    $result = mysqli_query($GLOBALS['db'], $sql);

    if (mysqli_num_rows($result) > 0) {
        $sql = 'UPDATE bills SET ';
        $existing_bill = mysqli_fetch_assoc($result);
        $sql_suffix = ' WHERE id=' . $existing_bill['id'];

        // Now that we know we're updating a bill, rather than adding a new one, delete the bill
        // from Memcached.
        if ($mc instanceof Memcached) {
            $mc->delete('bill-' . $existing_bill['id']);
        }

        $operation_type = 'update';
    } else {
        $sql = 'INSERT INTO bills
                SET date_created=now(), ';
        $operation_type = 'add';
    }

    // Prepare the data for the database.
    array_walk_recursive($bill, function (&$field) {
        $field = mysqli_real_escape_string($GLOBALS['db'], $field);
    });

    // Now create the code to insert the bill or update the bill, depending on what the last query
    // established for the preamble.
    $sql .= 'number="' . $bill['number'] . '", session_id="' . SESSION_ID . '",
			chamber="' . $bill['chamber'] . '", catch_line="' . $bill['catch_line'] . '",
			chief_patron_id=
				(SELECT id
				FROM representatives
				WHERE
					lis_id = "' . $bill['chief_patron_id'] . '"
				AND (
						date_ended IS NULL
						OR
						YEAR(date_ended)+1 = YEAR(now())
						OR
						YEAR(date_ended)-1 = YEAR(now())
						OR
						YEAR(date_ended) = YEAR(now())
					)
				AND chamber = "' . $bill['chamber'] . '"),
			last_committee_id=
				(SELECT id
				FROM committees
				WHERE lis_id = "' . $bill['last_committee'] . '" AND parent_id IS NULL
				AND chamber = "' . $bill['last_committee_chamber'] . '"),
			status="' . $bill['status'] . '"';
    if (isset($sql_suffix)) {
        $sql = $sql . $sql_suffix;
    }

    try {
        $result = mysqli_query($GLOBALS['db'], $sql);
    } catch (Exception $exception) {
        $log->put(
            'Adding ' . $bill['number'] . ' failed. This probably means that the legislator '
            . '(' . $bill['chief_patron_id'] . ', ' . strtolower($bill['chief_patron'])
            . ') who filed this bill isn’t in the database. Error: '
            . mysqli_error($GLOBALS['db']),
            4
        );
        unset($hashes[$number]);
        $missing_legislators[$bill['chief_patron_id']] = true;
        $result = false;
    }

    if ($result !== false) {
        // Log the addition or update
        if ($operation_type == 'add') {
            $log->put('Created ' . strtoupper($bill['number']) . ': '
                . stripslashes($bill['catch_line']) . ' (https://richmondsunlight.com/bill/'
                . SESSION_YEAR . '/' . $bill['number'] . '/)', 3);
        } elseif ($operation_type == 'update') {
            $log->put('Updated ' . strtoupper($bill['number']) . ': '
                . stripslashes($bill['catch_line']) . ' (https://richmondsunlight.com/bill/'
                . SESSION_YEAR . '/' . $bill['number']
                . '/)', 2);
        }

        // Get the last bill insert ID.
        if (!isset($existing_bill['id'])) {
            $bill['id'] = mysqli_insert_id($GLOBALS['db']);
        } else {
            $bill['id'] = $existing_bill['id'];
        }

        // Create a bill full text record for every version of the bill text that's filed.
        for ($i = 0; $i < count($bill['text']); $i++) {
            if (!empty($bill['text'][$i]['number']) && !empty($bill['text'][$i]['date'])) {
                $sql = 'INSERT INTO bills_full_text
                        SET bill_id = ' . $bill['id'] . ', number="' . $bill['text'][$i]['number'] . '",
                        date_introduced="' . $bill['text'][$i]['date'] . '", date_created=now()
                        ON DUPLICATE KEY UPDATE date_introduced=date_introduced';
                mysqli_query($GLOBALS['db'], $sql);
            }
        }
    }

    // Unset those variables to avoid reuse.
    unset($sql_suffix);
    unset($bill['id']);
    unset($existing_bill);
} // end looping through lines in this CSV file

// Store our per-bill hashes array to a file, so that we can open it up next time and see which
// bills have changed.
file_put_contents($hash_path, serialize($hashes));

// If any of these bills are patroned by legislators that we have no record of, log that.
if (count($missing_legislators) > 0) {
    $log->put('There are bills by ' . count($missing_legislators) . ' legislators that could not '
    . 'be added. That may because of encoding errors in the bill data, but it may be because these '
    . 'legislators are missing from the system.', 6);
}
