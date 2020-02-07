<?php

include '../../includes/class.Import.php';

$import = new Import();

$csv_line = '"HB1","Absentee voting; no excuse required.","H208","Herring","H22","Committee    Referral Pending","11/18/19","","","","","","","","N","N","N","N","N","N","N","N","HB1","11/18/19","","","","","","","","","","","H2201","","","","","11/18/19","H2201"';
$bill = str_getcsv($csv_line, ',', '"');
$bill = $import->prepare_bill($bill);

if ($bill['number'] != 'hb1')
{
    echo 'Error: Bill number was ' . $bill['number'] . ', expected "hb1"';
    $error = TRUE;
}

if ($bill['catch_line'] != 'Absentee voting; no excuse required.')
{
    echo 'Error: Catch line was ' . $bill['catch_line'] . ', expected "Absentee voting; no excuse required."';
    $error = TRUE;
}

if ($bill['chief_patron'] != 'Herring')
{
    echo 'Error: Chief patron was ' . $bill['chief_patron'] . ', expected "Herring"';
    $error = TRUE;
}

if ($bill['last_house_date'] != '1574035200')
{
    echo 'Error: Last house date was ' . $bill['last_house_date'] . ', expected 1574035200';
    $error = TRUE;
}

if ($error == TRUE)
{
    return FALSE;
}

echo 'All tests passed';
