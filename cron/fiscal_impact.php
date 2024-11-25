<?php

/*
Sometimes there is more than one fiscal impact statement. Sometimes they reflect changes
in the legislation, but sometimes I think they're from multiple agencies?

How to tell the difference between what's an updated FIS vs. an additional one? That is,
what replaces and what supplements?

Do we store the PDF URL? Because the provided filename isn't actually the filename.

Should this be in a new table?
*/

// Get the CSV
$filename = __DIR__ . '/FiscalImpactStatements.csv';
if (!file_exists($filename) || !is_readable($filename)) {
    exit($filename . ' not found or is not readable');
}
$csv = file($filename);

// Remove the header row
unset($csv[0]);

$fis_data = array_map('str_getcsv', $csv);

// Initialize the array that will store our finished data
$fis = [];

foreach ($fis_data as $statement) {
    $bill = strtolower($statement[0]);
    $bill = preg_replace('/([a-z]+)(0+)/', '$1', $bill);

    $filename = str_replace(strtoupper($bill), '', $statement[1]);
    $filename = trim(str_replace('.PDF', '', $filename));

    $fis[$bill] = $filename;
}

// Insert the records
foreach ($fis as $bill_number => $fis_id) {
    $sql = 'UPDATE bills
            SET impact_statement_id = "' . $fis_id . '"
            WHERE number = "' . $bill_number . '" AND
            session_id = ' . SESSION_ID;
    $result = mysqli_query($GLOBALS['db'], $sql);
    if ($result === false) {
        $log->put('Error: Adding a fiscal impact statement ID for ' . $bill_number . ' failed: '
            . mysqli_error($GLOBALS['db']), 4);
        if (mysqli_error($GLOBALS['db']) == 'MySQL server has gone away') {
            $log->put('Abandoning insertion of fiscal impact statement IDs.', 5);
            break;
        }
    } else {
        $log->put('Added a fiscal impact statement ID for ' . $bill_number . '.', 1);
    }
}
