<?php

/*
How to tell the difference between what's an updated FIS vs. an additional one? That is,
what replaces and what supplements?
*/

// Get the CSV
$filename = __DIR__ . '/FiscalImpactStatements.csv';
if (!file_exists($filename) || !is_readable($filename)) {
    exit($filename . ' not found or is not readable');
}
$csv = file($filename);

// See if it's CSV, or log an error message when there's no docket file (in the off season)
if (strpos(implode("\n", $csv), 'does not exist') === 0) {
    $log->put('Error: FiscalImpactStatements.csv does not contain CSV.', 3);
    fclose($docket_csv);
    return;
}

// Remove the header row
unset($csv[0]);

$fis_data = array_map('str_getcsv', $csv);

// Initialize the array that will store our finished data
$fis = [];

foreach ($fis_data as $statement) {
    $bill_number = strtolower($statement[0]);
    $lis_id = $statement[1];
    $pdf_url = $statement[2];

    $fis[] = array('bill_number' => $bill_number, 'lis_id' => $lis_id, 'pdf_url' => $pdf_url);
}

// Insert the records
foreach ($fis as $statement) {
    $sql = 'REPLACE INTO fiscal_impact_statements
            SET
                lis_id = "' . $statement['lis_id'] . '",
                bill_id=(
                    SELECT id
                    FROM bills
                    WHERE number = "' . $statement['bill_number'] . '"
                    AND session_id=' . SESSION_ID . '),
                pdf_url = "' . $statement['pdf_url'] . '",
                date_created = NOW()';
    $result = mysqli_query($GLOBALS['db'], $sql);
    if ($result === false) {
        $log->put('Error: Adding a fiscal impact statement ID for ' . $statement['bill_number']
            . ' failed: ' . mysqli_error($GLOBALS['db']), 4);
    } else {
        $log->put('Added or updated a fiscal impact statement ID for ' . $statement['bill_number']
            . '.', 2);
    }
}
