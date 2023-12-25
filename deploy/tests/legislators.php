<?php

include '../../includes/class.Import.php';

/*
 * Instantiate the logging class
 */
$log = new Log;

$import = new Import($log);

$test_records = array(
    array(
        'chamber' => 'senate',
        'lis_id' => 'S85',
        'expected_values' => array(
            'name_formal' => 'Adam P. Ebbin',
            'name' => 'Ebbin, Adam',
            'name_formatted' => 'Sen. Adam Ebbin (D-Alexandria)',
            'shortname' => 'apebbin',
            'chamber' => 'senate',
            'district_id' => '310',
            'party' => 'D',
            'lis_id' => '85',
            'photo_url' => 'https://apps.senate.virginia.gov/Senator/images/member_photos/Ebbin30',
            'email' => 'district30@senate.virginia.gov'
        )
    ),

    array(
        'chamber' => 'house',
        'lis_id' => 'H0314',
        'expected_values' => array(
            'name_formal' => 'Joshua G. Cole',
            'name' => 'Cole, Josh',
            'name_formatted' => 'Del. Josh Cole (D-Fredericksburg)',
            'shortname' => 'jgcole',
            'chamber' => 'house',
            'district_id' => '385',
            'date_started' => '2023-12-25',
            'party' => 'D',
            'lis_id' => 'H0314',
            'photo_url' => 'https://memdata.virginiageneralassembly.gov/images/display_image/H0314',
            'email' => 'DelJCole@house.virginia.gov'
        )
    )
);

/*
 * Iterate through our test records and ensure that the data extracted from LIS is accurate
 */
foreach ($test_records as $test_record)
{

    $legislator = $import->fetch_legislator_data($test_record['chamber'], $test_record['lis_id']);

    foreach ($test_record['expected_values'] as $key => $value)
    {
        if (!isset($legislator[$key]) || $legislator[$key] != $value)
        {
            echo 'Failure: For ' . pivot($legislator) .' expected a ' . $key . ' of value “'
                . $value . ',” but instead the value was “' . legislator[$key] . '”';
            $error = TRUE;
        }
    }

    if ( $import->fetch_photo($legislator['photo_url'], $legislator['shortname'] == false) )
    {
        echo 'Failure: Photo ' . $legislator['photo_url'] .' couldn’t be fetched';
        $error = TRUE;
    }

}

if ($error == TRUE)
{
    return FALSE;
}

echo 'All tests passed';
