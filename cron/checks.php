<?php

/**
 * Integrity Checks
 *
 * Run a few integrity checks on the site and its data
 *
 * @usage   Must be invoked from within update.php.
 */

if (LEGISLATIVE_SEASON == true) {
    /*
    * Make sure that the CSV files have been downloaded recently.
    */

    /*
    * A list of files to check, and their maximum age in hours.
    */
    $files = array(
           'bills.csv' => 10,
           'history.csv' => 10,
           'summaries.csv' => 24,
    );
    foreach ($files as $file => $age) {
        /*
         * If the file hasn't been updated in the prescribed period, report that.
         */
        if (time() - filemtime(__DIR__ . '/' . $file) > $age * 3600) {
            $file_age = (time() - filemtime($file)) / 3600;
            $log->put('Error: The local copy of ' . $file . ' hasn’t been updated in ' . $file_age
                . ' hours.', 7);
        }
    }

    /*
     * Make sure that the number of bills in the database equals the number in the CSV.
     */
    $csv_lines = file(__DIR__ . '/bills.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) - 1;
    $sql = 'SELECT *
            FROM bills
            WHERE session_id=' . SESSION_ID;
    $result = mysqli_query($GLOBALS['db'], $sql);
    $difference = $csv_lines - mysqli_num_rows($result);
    if ($difference != 0) {
        $log->put('Error: bills.csv has ' . $difference . ' more records than the database.');
    }

    /*
    * Make sure that the bill histories are being updated.
    */
    if (IN_SESSION == true) {
        // If it's M–F, 10 AM–5 PM
        if (( date('N') > 0 && date('N') < 6) && (date('G') > 10) & (date('G') < 17)) {
            $sql = 'SELECT *
                    FROM `bills_status`
                    WHERE date_created > NOW() - INTERVAL 24 HOUR
                    ORDER BY date_created DESC';
            $result = mysqli_query($GLOBALS['db'], $sql);
            if (mysqli_num_rows($result) == 0) {
                $log->put('Error: No bills have advanced in the past 24 hours. Bill histories are '
                    . 'not being updated. Make sure that history.csv is being updated, and that '
                    . 'data is being loaded correctly.', 6);
            }
        }
    }
}
