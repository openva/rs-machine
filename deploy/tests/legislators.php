<?php

include_once(__DIR__ . '/../../includes/settings.inc.php');
include_once(__DIR__ . '/../../includes/functions.inc.php');
include_once(__DIR__ . '/../../includes/vendor/autoload.php');

/*
 * Ensure we have an API key configured
 */
$lis_key = defined('LIS_KEY') ? trim(LIS_KEY) : '';
if ($lis_key === '') {
    echo "Skipping legislators test (LIS_KEY not configured).\n";
    return false;
}

/*
 * Instantiate the logging class
 */
$log = new Log();

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
            'district_id' => 319,
            'party' => 'D',
            'lis_id' => '85',
            'photo_url' => 'https://apps.senate.virginia.gov/Senator/images/member_photos/Ebbin39',
            'email' => 'senatorebbin@senate.virginia.gov'
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
$error = false;
foreach ($test_records as $test_record) {
    $legislator = $import->fetch_legislator_data_api($test_record['chamber'], $test_record['lis_id']);
    if ($legislator === false) {
        echo 'Failure: API lookup returned no data for ' . $test_record['lis_id'] . "\n";
        $error = true;
        continue;
    }

    foreach ($test_record['expected_values'] as $key => $value) {
        if (!isset($legislator[$key]) || $legislator[$key] != $value) {
            $who = isset($legislator['name_formatted']) ? $legislator['name_formatted'] : $test_record['lis_id'];
            $actual = isset($legislator[$key]) ? $legislator[$key] : '(missing)';
            echo 'Failure: For ' . $who . ' expected ' . $key . ' to equal “'
                . $value . ',” but found “' . $actual . '”.' . "\n";
            $error = true;
        }
    }

    $photo_path = $import->fetch_photo($legislator['photo_url'], $legislator['shortname']);
    if ($photo_path === false) {
        echo 'Failure: Photo ' . $legislator['photo_url'] . ' couldn’t be fetched' . "\n";
        $error = true;
    } elseif (is_string($photo_path) && file_exists($photo_path)) {
        unlink($photo_path);
    }
}

if ($error === true) {
    return false;
}

echo 'All legislators tests passed' . "\n";
