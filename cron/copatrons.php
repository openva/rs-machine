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
    $log->put('copatrons: Could not fetch Sponsors.csv', 5);
    return;
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
    $log->put('copatrons: Sponsors.csv unchanged', 2);
    return;
}

/*
 * Parse the CSV from the string.
 */
$fp = fopen('php://memory', 'r+');
fwrite($fp, $csv_raw);
rewind($fp);

$header = fgetcsv($fp, 0, ',');
if ($header === false) {
    $log->put('copatrons: Sponsors.csv is empty', 5);
    fclose($fp);
    return;
}

/*
 * Build a lookup of LIS member IDs (e.g. "H0173") to RS legislator IDs.
 */
$sql = 'SELECT id, lis_id, chamber
        FROM representatives
        WHERE lis_id IS NOT NULL
        AND (date_ended IS NULL OR date_ended > now())';
$result = mysqli_query($GLOBALS['db'], $sql);

$legislators = [];
while ($legislator = mysqli_fetch_array($result)) {
    $key = strtoupper($legislator['chamber'][0])
        . str_pad($legislator['lis_id'], 4, '0', STR_PAD_LEFT);
    $legislators[$key] = $legislator['id'];
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
        continue;
    }

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
    $to_remove = array_diff_key($db_set, $csv_set);
    foreach (array_keys($to_remove) as $legislator_id) {
        $delete_stmt->bindParam(':bill_id', $bill_id, PDO::PARAM_INT);
        $delete_stmt->bindParam(':legislator_id', $legislator_id, PDO::PARAM_INT);
        $delete_stmt->execute();
        $deleted++;
    }

    /*
     * Update the copatron count on the bill.
     */
    $count = count($csv_set);
    $sql = 'UPDATE bills SET copatrons = ' . $count . ' WHERE id = ' . $bill_id;
    mysqli_query($GLOBALS['db'], $sql);
}

/*
 * Save the hash so we skip unchanged files.
 */
if ($mc instanceof Memcached) {
    $mc->set('sponsors-csv-hash', $sponsors_hash);
}

$log->put('copatrons: ' . $inserted . ' added, ' . $deleted . ' removed.', 2);
