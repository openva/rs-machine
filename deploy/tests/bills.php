<?php

include_once(__DIR__ . '/../../includes/settings.inc.php');
include_once(__DIR__ . '/../../includes/functions.inc.php');
include_once(__DIR__ . '/../../includes/vendor/autoload.php');

/*
 * Instantiate the logging class
 */
$log = new Log;
$import = new Import($log);

$csv_line = '"HB1","Absentee voting; no excuse required.","H208","Herring","H22","Committee    Referral Pending","11/18/19","","","","","","","","N","N","N","N","N","N","N","N","HB1","11/18/19","","","","","","","","","","","H2201","","","","","11/18/19","H2201"';
$bill = str_getcsv($csv_line, ',', '"');
$bill = $import->prepare_bill($bill);

if ($bill['number'] != 'hb1')
{
    echo 'Error: Bill number was ' . $bill['number'] . ', expected "hb1"' . "\n";
    $error = TRUE;
}

if ($bill['catch_line'] != 'Absentee voting; no excuse required.')
{
    echo 'Error: Catch line was ' . $bill['catch_line']
        . ', expected "Absentee voting; no excuse required." . "\n"';
    $error = TRUE;
}

if ($bill['chief_patron'] != 'Herring')
{
    echo 'Error: Chief patron was ' . $bill['chief_patron'] . ', expected "Herring"' . "\n";
    $error = TRUE;
}

if ($bill['last_house_date'] != '1574053200')
{
    echo 'Error: Last house date was ' . $bill['last_house_date'] . ', expected 1574053200' . "\n";
    $error = TRUE;
}

if (isset($error) && $error == TRUE)
{
    return FALSE;
}

echo 'All bills tests passed' . "\n";
