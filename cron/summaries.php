<?php

###
# UPDATE BILL SUMMARIES
###

/*
 * Connect to Memcached, since we'll be interacting with it during this session.
 */
$mc = new Memcached();
$mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);

/*
 * Don't bother if the file hasn't changed.
 */
$summaries_hash = md5(file_get_contents(__DIR__ . '/summaries.csv'));
if ($mc->get('summaries-csv-hash') == $summaries_hash) {
    $log->put('Bill summaries unchanged', 2);
    return;
}

/*
 * Save the new hash.
 */
$mc->set('summaries-csv-hash', $summaries_hash);

/*
 * Open the file.
 */
$fp = fopen(__DIR__ . '/summaries.csv', 'r');
if ($fp === false) {
    $log->put('summaries.csv could not be read from the filesystem.', 8);
    return false;
}

/*
 * Also, retrieve our saved serialized array of hash data, so that we can only update or insert
 * summaries that have changed, or that are new.
 */
$hash_path = __DIR__ . '/hashes/summaries-' . SESSION_ID . '.md5';
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
 * Generate a list of all bills and their numbers, to use to make comparisons.
 */
$sql = 'SELECT bills.id, bills.number
		FROM bills
		WHERE session_id = ' . $session_id;
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) > 0) {
    $bills = array();
    while ($bill = mysqli_fetch_array($result)) {
        $bills[$bill{number}] = $bill['id'];
    }
}

/*
 * Set a flag that will allow us to ignore the header row.
 */
$first = 'yes';

/*
 * This script often hits the default lock wait timeout of 50 seconds, resulting in the summary not
 * being updated. We double it to 100, since there's no hurry here.
 */
$sql = 'SET innodb_lock_wait_timeout=100';
mysqli_query($GLOBALS['db'], $sql);

/*
 * Step through each row in the CSV file, one by one.
 */
while (($summary = fgetcsv($fp, 1000, ',')) !== false) {
    # If this is something other than a header row, parse it.
    if (isset($first)) {
        unset($first);
        continue;
    }

    /*
     * Rename each field to something reasonable.
     */
    $new_headers = array(
            'number',
            'doc_id',
            'type',
            'text'
        );
    foreach ($new_headers as $old => $new) {
        $summary[$new] = $summary[$old];
        unset($summary[$old]);
    }

    /*
     * Change the format of the bill number. In this file, the numeric portions are left-padded
     * with zeros, so that e.g. HB1 is rendered as HB0001. Here we change them to e.g. HB1.
     */
    $suffix = substr($summary['number'], 2) + 0;
    $summary['number'] = substr($summary['number'], 0, 2) . $suffix;


    /*
     * Before we proceed any farther, see if this record is either new or different than last
     * time that we examined it.
     */
    $hash = md5(serialize($summary));
    $number = strtolower($summary['number']);

    if (isset($hashes[$number]) && ($hash == $hashes[$number])) {
        continue;
    } else {
        $hashes[$number] = $hash;
        if (!isset($hashes[$number])) {
            $log->put('Adding summary ' . $summary['number'] . '.', 2);
        } else {
            $log->put('Updating summary ' . $summary['number'] . '.', 1);
        }
    }

    /*
     * Remove the paragraph tags, newlines, NBSPs and double spaces.
     */
    $summary['text'] = str_replace("\r", ' ', $summary['text']);
    $summary['text'] = str_replace("\n", ' ', $summary['text']);
    $summary['text'] = str_replace('&nbsp;', ' ', $summary['text']);
    $summary['text'] = str_replace('  ', ' ', $summary['text']);
    $summary['text'] = str_replace('\u00a0', ' ', $summary['text']);

    # There is often an HTML mistake in this tag, so we perform this replacement after
    # running HTML Purifier, not before.
    $summary['text'] = str_replace('<br clear="all" /> ', ' ', $summary['text']);
    $summary['text'] = strip_tags($summary['text'], '<b><i><em><strong>');

    # Run the summary through HTML Purifier.
    #$config = HTMLPurifier_Config::createDefault();
    #$purifier = new HTMLPurifier($config);
    #$summary['text'] = $purifier->purify($summary['text']);

    # Clean up the bolding, so that we don't bold a blank space.
    $summary['text'] = str_replace(' </b>', '</b> ', $summary['text']);

    # Trim off any whitespace.
    $summary['text'] = trim($summary['text']);

    # Hack off a hanging non-breaking space, if there is one.
    if (substr($summary['text'], -7) == ' &nbsp;') {
        $summary['text'] = substr($summary['text'], 0, -8);
    }

    /*
     * If we have any summary text, store it in the database.
     */
    if (!empty($summary['text'])) {
        /*
         * Look up the bill ID for this bill number.
         */
        $bill_id = $bills[strtolower($summary{number})];
        if (empty($bill_id)) {
            $log->put('Summary found for ' . $summary['number']
                . ', but we have no record of that bill.', 2);
            continue;
        }

        /*
         * Commit this to the database.
         */
        $sql = 'UPDATE bills
				SET summary="' . mysqli_real_escape_string($GLOBALS['db'], $summary['text']) . '"
				WHERE id=' . $bill_id;
        $result = mysqli_query($GLOBALS['db'], $sql);
        if (!$result) {
            $log->put('Insertion of ' . strtoupper($summary['number']) . ' summary failed. '
                . 'Error: ' . mysqli_error($GLOBALS['db']) . ' SQL: ' . $sql, 6);
        }
    } else {
        $log->put('Summary of ' . strtoupper($summary['number']) . ' is blank.', 2);
    }
} // end looping through lines in this CSV file

# Close the CSV file.
fclose($fp);

# Store our per-bill hashes array to a file, so that we can open it up next time and see which
# bills have changed.
file_put_contents($hash_path, serialize($hashes));
