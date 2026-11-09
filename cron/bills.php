<?php

use RsMachine\BillsCsv;
use RsMachine\CatchLineRepair;

/*
 * Instantiate the logging class
 */
$log = new Log();

/*
 * Build a map of bill number => legislation ID for the current session, using the provided
 * $api_session_id. This uses the public LIS endpoints and stores the results in $bill_ids_map.
 */
$bill_ids_map = array();
$lis_headers = array(
    'Content-Type: application/json',
    'WebAPIKey: ' . LIS_KEY,
);

if (!empty($session_api_id)) {
    $lis_bills = curl_init('https://lis.virginia.gov/Legislation/api/GetLegislationIdsListAsync');
    curl_setopt($lis_bills, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($lis_bills, CURLOPT_HTTPHEADER, $lis_headers);
    curl_setopt($lis_bills, CURLOPT_TIMEOUT, 30);
    curl_setopt($lis_bills, CURLOPT_POST, true);
    curl_setopt(
        $lis_bills,
        CURLOPT_POSTFIELDS,
        json_encode(array('sessionId' => (int) $session_api_id))
    );

    $bills_response = curl_exec($lis_bills);
    $bills_status = curl_getinfo($lis_bills, CURLINFO_HTTP_CODE);

    if ($bills_response !== false && $bills_status < 400) {
        $bills_json = json_decode($bills_response, true);
        if (isset($bills_json['LegislationIds']) && is_array($bills_json['LegislationIds'])) {
            foreach ($bills_json['LegislationIds'] as $bill_entry) {
                if (isset($bill_entry['LegislationNumber'], $bill_entry['LegislationID'])) {
                    $bill_ids_map[strtolower($bill_entry['LegislationNumber'])] = (int) $bill_entry['LegislationID'];
                }
            }
        } else {
            $log->put('LIS bill list response missing LegislationIds.', 5);
        }
    } else {
        $log->put('Failed to retrieve LIS bill list (HTTP ' . $bills_status . ').', 5);
    }
} else {
    $log->put('Missing $api_session_id; cannot retrieve LIS bill list.', 5);
}

/*
 * Without the list, bills are still stored, but without their LIS IDs, which history.php needs.
 * Those are filled in once the list is available again (see the end of this file).
 */
$bill_list_failed = ($bill_ids_map === []);

/*
 * Open the bills CSV. The runner removes it when LIS has none for this session.
 */
if (!file_exists(__DIR__ . '/bills.csv')) {
    return \RsMachine\TaskResult::skipped('LIS has no BILLS.CSV for this session');
}
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
 * legislators, and missing bills.
 */
$missing_legislators = array();
$missing_bills = array();

/*
 * Step through each row in the CSV, one by one.
 */
$bills = explode("\n", $bills);
unset($bills[0]);
foreach ($bills as $bill) {
    $bill = str_getcsv($bill, ',', '"');

    // Before we proceed any farther, see if this record is either new or different than last time
    // that we examined it. Rows whose catch line needs repair from the LIS API (see below) get a
    // distinct hash, so that rows stored before that repair existed are processed once more.
    $hash = $unrepaired_hash = md5(serialize($bill));
    $number = strtolower(trim($bill[0]));
    $catch_line_damaged = isset($bill[1]) && CatchLineRepair::isDamaged($bill[1]);
    if ($catch_line_damaged) {
        $hash = md5($unrepaired_hash . ':catch-line-repair');
    }

    if (isset($hashes[$number]) && ($hash == $hashes[$number])) {
        continue;
    } else {
        $is_new = !isset($hashes[$number]);
        $hashes[$number] = $hash;
        if ($is_new) {
            $log->put('Adding ' . strtoupper($number) . '.', 2);
        } else {
            $log->put('Updating ' . strtoupper($number) . '.', 2);
        }
    }

    /*
     * Clean up the bill CSV
     */
    $import = new Import($log);
    $introduced = BillsCsv::introductionDate($bill);
    $text_versions = BillsCsv::textVersions($bill);
    $bill = $import->prepare_bill($bill);

    // LIS's CSV replaces every non-ASCII character in the catch line with "?" (e.g. "Damaré"
    // becomes "Damar?"). Recover the original from the LIS API, which has it intact.
    if ($catch_line_damaged) {
        try {
            if (!isset($bill_ids_map[$bill['number']]) || LIS_KEY == '') {
                throw new RuntimeException('no LIS legislation ID or API key available');
            }

            $repaired = CatchLineRepair::fetch(
                $bill_ids_map[$bill['number']],
                trim($bill[1]),
                LIS_KEY
            );

            if ($repaired !== null) {
                $purifier = new HTMLPurifier();
                $bill['catch_line'] = trim($purifier->purify($repaired));
            } else {
                $log->put('The LIS API has no title matching the catch line of '
                    . strtoupper($bill['number']) . ' (“' . $bill['catch_line'] . '”), so the '
                    . 'characters LIS’s CSV replaced with “?” could not be recovered.', 4);
            }
        } catch (RuntimeException $e) {
            $log->put('Could not retrieve the title of ' . strtoupper($bill['number'])
                . ' from the LIS API (' . $e->getMessage() . '); will retry next time.', 3);

            // Store the unmarked hash, so that this row is processed again on the next run.
            $hashes[$number] = $unrepaired_hash;
        }
    }

    // Check to see if the bill is already in the database.
    $sql = 'SELECT id
			FROM bills
			WHERE
                number="' . $bill['number'] . '" AND
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
    $sql .= 'number="' . $bill['number'] . '",
            session_id="' . SESSION_ID . '",
			chamber="' . $bill['chamber'] . '",
            catch_line="' . $bill['catch_line'] . '",
			chief_patron_id=
				(SELECT person_id
				FROM terms
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
				WHERE
                    lis_id = "' . $bill['last_committee'] . '" AND
                    parent_id IS NULL AND
                    date_ended IS NULL AND
                    chamber = "' . $bill['last_committee_chamber'] . '"
                ),
			status="' . $bill['status'] . '"';

    // A bill that LIS's list lacks (or the list itself, if it couldn't be retrieved) keeps any
    // LIS ID it has.
    if (isset($bill_ids_map[$bill['number']])) {
        $sql .= ', lis_id=' . (int) $bill_ids_map[$bill['number']];
    }

    // LIS's date of introduction is better than any we can infer: see cleanup.php's fallbacks.
    if ($introduced !== null) {
        $sql .= ', date_introduced="' . $introduced . '"';
    }
    if (isset($sql_suffix)) {
        $sql = $sql . $sql_suffix;
    }

    try {
        $result = mysqli_query($GLOBALS['db'], $sql);
        $error = $result === false ? mysqli_error($GLOBALS['db']) : '';
    } catch (Exception $exception) {
        $result = false;
        $error = $exception->getMessage();
    }

    if ($result === false) {
        // Retry this bill next time.
        unset($hashes[$number]);

        // The chief patron is looked up by a subquery, which finds no one when the legislator
        // isn't in the database, leaving chief_patron_id null.
        if (stripos($error, 'chief_patron_id') !== false) {
            $log->put('Adding ' . strtoupper($bill['number']) . ' failed, because its chief patron ('
                . $bill['chief_patron_id'] . ', ' . strtolower($bill['chief_patron'])
                . ') isn’t in the database.', 2);
            $missing_bills[] = $bill['number'];
            $missing_legislators[$bill['chief_patron_id']] = $bill['chief_patron'];
        } else {
            $log->put('Storing ' . strtoupper($bill['number']) . ' failed: ' . $error, 5);
        }
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
        foreach ($text_versions as $version => $date) {
            $sql = 'INSERT INTO bills_full_text
                    SET
                        bill_id = ' . $bill['id'] . ',
                        number="' . $version . '",
                        session_id=' . SESSION_ID . ',
                        date_introduced="' . $date . '",
                        date_created=now()
                    ON DUPLICATE KEY UPDATE date_introduced=date_introduced';
            mysqli_query($GLOBALS['db'], $sql);
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
    $log->put('There are ' . count($missing_bills) . ' bills by '
    . count($missing_legislators) . ' legislators that could not be added. That may because of '
    . 'encoding errors in the bill data, but it is probably because those legislators '
    . '(' . implode(', ', $missing_legislators) . ') '
    . 'are missing from our database.', 6);
}

/*
 * Fill in the LIS IDs of this session's bills that lack them: those stored while LIS's list was
 * unavailable, or before it included them. (A bill is otherwise only updated when its row in the
 * CSV changes.)
 */
if ($bill_ids_map !== []) {
    $result = mysqli_query(
        $GLOBALS['db'],
        'SELECT id, number FROM bills WHERE session_id = ' . (int) SESSION_ID . ' AND lis_id IS NULL'
    );
    $filled = 0;
    while ($result !== false && ($row = mysqli_fetch_assoc($result))) {
        if (!isset($bill_ids_map[$row['number']])) {
            continue;
        }
        // Depending on how mysqli reports errors, a failure is false or an exception.
        try {
            $updated = mysqli_query($GLOBALS['db'], 'UPDATE bills SET lis_id = '
                . (int) $bill_ids_map[$row['number']] . ' WHERE id = ' . (int) $row['id']);
            $error = $updated ? '' : mysqli_error($GLOBALS['db']);
        } catch (Exception $exception) {
            $updated = false;
            $error = $exception->getMessage();
        }
        if ($updated) {
            $filled++;
        } else {
            $log->put('Could not record the LIS ID of ' . strtoupper($row['number']) . ': ' . $error, 5);
        }
    }
    if ($filled > 0) {
        $log->put('Recorded the LIS IDs of ' . $filled . ' bills that lacked them.', 3);
    }
}

if ($bill_list_failed) {
    return \RsMachine\TaskResult::failed('LIS’s list of bills couldn’t be retrieved, so new bills were '
        . 'stored without their LIS IDs, and their history can’t be fetched until it can');
}
