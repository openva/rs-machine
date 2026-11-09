<?php

/*
 * Gather and record bill copatrons from the Sponsors CSV published by LIS.
 */

/*
 * Fetch the sponsors CSV.
 */
$session_lis_id = SESSION_LIS_ID;
if (strlen($session_lis_id) === 3) {
    $session_lis_id = '20' . $session_lis_id;
}

$csv_url = 'https://lis.blob.core.windows.net/lisfiles/' . $session_lis_id . '/Sponsors.csv';
$csv_raw = get_content($csv_url);
if ($csv_raw === false) {
    return \RsMachine\TaskResult::failed('could not download Sponsors.csv');
}

/*
 * Don't bother if the file hasn't changed.
 */
$mc = null;
if (MEMCACHED_SERVER != '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

$sponsors_hash = md5($csv_raw);
if ($mc instanceof Memcached && $mc->get('sponsors-csv-hash') == $sponsors_hash) {
    return \RsMachine\TaskResult::skipped('Sponsors.csv is unchanged');
}

/*
 * Parse the CSV from the string.
 */
$fp = fopen('php://memory', 'r+');
fwrite($fp, $csv_raw);
rewind($fp);

$header = fgetcsv($fp, 0, ',');
if ($header === false) {
    fclose($fp);
    return \RsMachine\TaskResult::failed('Sponsors.csv is empty');
}

/*
 * Look up LIS member IDs (e.g. "H0173") among everyone who served during this session, not just
 * current legislators, so that copatrons who've since left office aren't deleted.
 */
$legislators = \RsMachine\SessionMembers::lookUp($GLOBALS['dbh'], (int) SESSION_ID);
if ($legislators === []) {
    fclose($fp);
    return \RsMachine\TaskResult::failed('no legislators were found for this session, so copatrons '
        . 'weren’t updated (they would all have been deleted)');
}

/*
 * Build a lookup of bill numbers to bill IDs and chief patron IDs.
 */
$sql = 'SELECT id, number, chief_patron_id
        FROM bills
        WHERE session_id = ' . SESSION_ID;
$result = mysqli_query($GLOBALS['db'], $sql);

$bills = [];
while ($bill = mysqli_fetch_array($result)) {
    $bills[strtolower($bill['number'])] = [
        'id' => $bill['id'],
        'chief_patron_id' => $bill['chief_patron_id'],
    ];
}

/*
 * Parse each row and group copatrons by bill.
 */
$bill_copatrons = [];

/*
 * Copatrons whose member ID matches no one who served this session, by bill. Such a bill's stored
 * copatrons aren't removed, since one of them may be the copatron that wasn't matched.
 */
$unmatched = [];

while (($row = fgetcsv($fp, 0, ',')) !== false) {
    if (count($row) < 4) {
        continue;
    }

    $member_id = trim($row[1]);
    $bill_number = strtolower(trim($row[2]));
    $patron_type = trim($row[3]);

    /*
     * Skip chief patrons — they're already stored in bills.chief_patron_id.
     */
    if (strpos($patron_type, 'Chief Patron') !== false && strpos($patron_type, 'Co-Patron') === false) {
        continue;
    }

    if (!isset($bills[$bill_number])) {
        continue;
    }

    if (!isset($legislators[$member_id])) {
        $unmatched[$bills[$bill_number]['id']][$member_id] = true;
        continue;
    }

    // A person's ID, like bills.chief_patron_id.
    $legislator_id = $legislators[$member_id];
    $bill_data = $bills[$bill_number];

    /*
     * Skip if this legislator is the chief patron.
     */
    if ($legislator_id == $bill_data['chief_patron_id']) {
        continue;
    }

    $bill_copatrons[$bill_data['id']][$legislator_id] = true;
}

fclose($fp);

/*
 * Get existing copatrons from the database to compute diffs.
 */
$sql = 'SELECT bill_id, legislator_id
        FROM bills_copatrons
        WHERE bill_id IN (
            SELECT id FROM bills WHERE session_id = ' . SESSION_ID . '
        )';
$result = mysqli_query($GLOBALS['db'], $sql);

$existing = [];
while ($row = mysqli_fetch_array($result)) {
    $existing[$row['bill_id']][$row['legislator_id']] = true;
}

/*
 * Insert new copatrons and remove expired ones.
 */
$insert_stmt = $GLOBALS['dbh']->prepare(
    'INSERT IGNORE INTO bills_copatrons (bill_id, legislator_id, date_created)
     VALUES (:bill_id, :legislator_id, NOW())'
);

$delete_stmt = $GLOBALS['dbh']->prepare(
    'DELETE FROM bills_copatrons WHERE bill_id = :bill_id AND legislator_id = :legislator_id'
);

$inserted = 0;
$deleted = 0;

/*
 * Process all bills in the current session.
 */
$all_bill_ids = array_unique(
    array_merge(array_keys($bill_copatrons), array_keys($existing))
);

$changed_bills = [];
foreach ($all_bill_ids as $bill_id) {
    $csv_set = $bill_copatrons[$bill_id] ?? [];
    $db_set = $existing[$bill_id] ?? [];

    /*
     * Insert copatrons that are in the CSV but not in the database.
     */
    $to_add = array_diff_key($csv_set, $db_set);
    foreach (array_keys($to_add) as $legislator_id) {
        $insert_stmt->bindParam(':bill_id', $bill_id, PDO::PARAM_INT);
        $insert_stmt->bindParam(':legislator_id', $legislator_id, PDO::PARAM_INT);
        $insert_stmt->execute();
        $inserted++;
    }

    /*
     * Remove copatrons that are in the database but not in the CSV.
     */
    $to_remove = isset($unmatched[$bill_id]) ? [] : array_diff_key($db_set, $csv_set);
    foreach (array_keys($to_remove) as $legislator_id) {
        $delete_stmt->bindParam(':bill_id', $bill_id, PDO::PARAM_INT);
        $delete_stmt->bindParam(':legislator_id', $legislator_id, PDO::PARAM_INT);
        $delete_stmt->execute();
        $deleted++;
    }

    if ($to_add !== [] || $to_remove !== []) {
        $changed_bills[] = $bill_id;
    }

    /*
     * Update the copatron count on the bill.
     */
    $count = count($csv_set) + count($unmatched[$bill_id] ?? []);
    $sql = 'UPDATE bills SET copatrons = ' . $count . ' WHERE id = ' . $bill_id;
    mysqli_query($GLOBALS['db'], $sql);
}

// The website's cached records of these bills list their copatrons.
\RsMachine\BillCache::forget($mc instanceof Memcached ? $mc : null, $changed_bills);

/*
 * Save the hash so we skip unchanged files.
 */
if ($mc instanceof Memcached) {
    $mc->set('sponsors-csv-hash', $sponsors_hash);
}

$log->put('copatrons: ' . $inserted . ' added, ' . $deleted . ' removed.', 2);
if ($unmatched !== []) {
    $members = [];
    foreach ($unmatched as $ids) {
        $members += $ids;
    }
    $log->put('copatrons: ' . count($members) . ' member IDs in Sponsors.csv (' . implode(', ', array_keys($members))
        . ') match no one who served this session, so ' . count($unmatched) . ' bills’ copatrons '
        . 'were added to but not removed from. A term’s dates may be wrong.', 5);
}
