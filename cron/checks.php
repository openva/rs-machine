<?php

/**
 * Integrity Checks
 *
 * Run a few integrity checks on the site and its data
 *
 * @usage   Must be invoked from within update.php.
 */

if (IN_SESSION == true) {
    /*
    * Make sure that the CSV files have been downloaded recently.
    */

    /*
    * A list of files to check, and their maximum age in hours.
    */
    $files = array(
           'bills.csv' => 10,
           'summaries.csv' => 24,
    );
    foreach ($files as $file => $age) {
        /*
         * If the file hasn't been updated in the prescribed period, report that.
         */
        if (time() - filemtime(__DIR__ . '/' . $file) > $age * 3600) {
            $file_age = (time() - filemtime(__DIR__ . '/' . $file)) / 3600;
            $log->put('Error: The local copy of ' . $file . ' hasn’t been updated in ' . $file_age
                . ' hours.', 7);
        }
    }

    /*
     * Make sure that the number of bills in the database equals the number in the CSV.
     */
    $csv_rows = array_map('str_getcsv', file(__DIR__ . '/bills.csv'));
    $csv_header = array_shift($csv_rows);
    $bill_id_col = array_search('Bill_id', $csv_header);
    $csv_bill_numbers = array_map(function ($row) use ($bill_id_col) {
        return strtolower(trim($row[$bill_id_col]));
    }, $csv_rows);

    $sql = 'SELECT number
            FROM bills
            WHERE session_id=' . SESSION_ID;
    $result = mysqli_query($GLOBALS['db'], $sql);
    $db_bill_numbers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $db_bill_numbers[] = strtolower(trim($row['number']));
    }

    $only_in_csv = array_diff($csv_bill_numbers, $db_bill_numbers);
    $only_in_db  = array_diff($db_bill_numbers, $csv_bill_numbers);
    if (!empty($only_in_csv) || !empty($only_in_db)) {
        $difference = count($csv_bill_numbers) - count($db_bill_numbers);
        $log->put('Error: bills.csv has ' . $difference . ' more records than the database.', 5);
        if (!empty($only_in_csv)) {
            $log->put('Bills in CSV but not database: ' . implode(', ', $only_in_csv), 5);
        }
        if (!empty($only_in_db)) {
            $log->put('Bills in database but not CSV: ' . implode(', ', $only_in_db), 5);
        }
    }

    /*
    * Make sure that the bill histories are being updated.
    */
    // If it's M–F, 10 AM–5 PM
    if (( date('N') > 0 && date('N') < 6) && (date('G') > 10) & (date('G') < 17)) {
        $sql = 'SELECT *
                FROM `bills_status`
                WHERE date_created > CONVERT_TZ(NOW(), "UTC", "America/New_York") - INTERVAL 4 HOUR
                ORDER BY date_created DESC';
        $result = mysqli_query($GLOBALS['db'], $sql);
        if (mysqli_num_rows($result) == 0) {
            $log->put('Error: No bills have advanced in the past 4 hours. Bill histories are '
                . 'not being updated. Make sure that histories are being updated, and that '
                . 'data is being loaded correctly.', 6);
        }
    }
}
