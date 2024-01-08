<?php

/**
 * Integrity Checks
 *
 * Run a few integrity checks on the site and its data
 *
 * @usage	Must be invoked from within update.php.
 */

 if (LEGISLATIVE_SEASON == true)
 {

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
    foreach ($files as $file => $age)
    {

        /*
         * If the file hasn't been updated in the prescribed period, report that.
         */
        if (time()-filemtime(__DIR__ . '/' . $file) > $age * 3600)
        {
            $file_age = (time()-filemtime($file)) / 3600;
            $log->put('Error: The local copy of ' . $file . ' hasn’t been updated in ' . $file_age
                . ' hours.', 7);
        }

    }

    /*
     * Make sure that the bill histories are being updated.
     */
    // If it's M–F, 10 AM–5 PM
    if ( ( date('N') > 0 && date('N') < 6) && (date('G') > 10) & (date('G') < 17) )
    {
        $sql = 'SELECT *
                FROM `bills_status`
                WHERE date_created > NOW() - INTERVAL 3 HOUR
                ORDER BY date_created DESC';
        $result = mysql_query($sql);
        if (mysql_num_rows($result) == 0)
        {
            $log->put('Error: No bills have advanced in the past 3 hours, which is unusual. '
                . 'There may be a problem. Make sure that summaries.csv is being updated, and '
                . 'that data is being loaded correctly.', 6);
        }
    }

 }
