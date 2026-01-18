<?php

###
# Retrieve and Store Dockets
#
# PURPOSE
# Retrieves the dockets from every planned meeting of the Senate and stores them.
#
# NOTES
# None.
#
###

// Create an array of upcoming dates for which there could plausibly be dockets, going out 5 days.
$date = time();
$dates = array();
$dates[] = date('Y-m-d', time());
for ($i = 0; $i < 6; $i++) {
    $date = $date + (60 * 60 * 24);
    $dates[] = date('Y-m-d', $date);
}

# We start by clearing out the old docket data, since we're replacing it with new
# data. This is necessary to avoid continuing to list bills that were once on the
# docket, but are no longer. (If we only ever add new bills, then we have no
# method of deleting old bills.)
foreach ($dates as $date) {
    $sql = 'DELETE FROM dockets
            WHERE date="' . $date . '"';
    mysqli_query($GLOBALS['db'], $sql);
}

// Open the CSV file
$docket_csv = fopen(__DIR__ . '/docket.csv', 'r');
if (IN_SESSION === true) {
        $docket_alert_level = 6;
} else {
        $docket_alert_level = 2;
}
if ($docket_csv === false) {
    $log->put('Error: docket.csv is missing.', $docket_alert_level);
    return;
}

// See if it's CSV, or log an error message when there's no docket file (in the off season)
if (stripos(fgets($docket_csv), 'Doc_date') == false) {
    $log->put('Error: docket.csv does not contain docket records.', $docket_alert_level);
    fclose($docket_csv);
    return;
}

// Skip the header row
fgetcsv($docket_csv);

// Process each row in the CSV file
$errors = false;
$updates = false;
while (($row = fgetcsv($docket_csv)) !== false) {
    $lis_id = $row[0];
    $date = date('Y-m-d', strtotime($row[1]));
    $bill_number = strtolower($row[3]);

    // Convert LIS ID from LIS's format into our own
    $chamber = ($lis_id[0] === 'H') ? 'house' : 'senate';
    $lis_id = (int)substr($lis_id, 1);

    // Don't bother with docket entries that aren't in our list of dates
    if (!in_array($date, $dates)) {
        continue;
    }

    $updates = true;

    // Insert the meeting data into the dockets table.
    $sql = 'INSERT INTO dockets
            SET date="' . $date . '",
            committee_id=(
                SELECT id
                FROM committees
                WHERE
                    chamber="' . $chamber . '" AND
                    lis_id="' . $lis_id . '" AND
                    date_ended IS NULL AND
                    parent_id IS NULL),
            bill_id=
                (SELECT id
                FROM bills
                WHERE
                    number="' . $bill_number . '" AND
                    session_id=' . SESSION_ID . '),
            date_created=now()
            ON DUPLICATE KEY UPDATE id=id';
    $result = mysqli_query($GLOBALS['db'], $sql);
    if ($result === false) {
        $log->put('Error: Could not create docket entry. ' . $sql, 4);
        $errors = true;
    }
}

if ($errors === false && $updates === true) {
    $log->put('Docket entries added successfully.', 3);
}

// Close the CSV file
fclose($docket_csv);
