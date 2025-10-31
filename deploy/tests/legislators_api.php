<?php

include_once(__DIR__ . '/../../includes/settings.inc.php');
include_once(__DIR__ . '/../../includes/functions.inc.php');
include_once(__DIR__ . '/../../includes/vendor/autoload.php');

$lis_key = defined('LIS_KEY') ? trim(LIS_KEY) : '';
if ($lis_key === '') {
    echo "Skipping fetch_legislator_data_api test (LIS_KEY not configured).\n";
    return false;
}

$log = new Log();
$import = new Import($log);

$test_records = array(
    array(
        'chamber' => 'senate',
        'lis_id' => 'S0085'
    ),
    array(
        'chamber' => 'house',
        'lis_id' => 'H0314'
    )
);

$required_keys = array(
    'name_formal',
    'name',
    'name_formatted',
    'shortname',
    'chamber',
    'district_id',
    'party',
    'lis_id'
);

foreach ($test_records as $test_record) {
    $result = $import->fetch_legislator_data_api($test_record['chamber'], $test_record['lis_id']);

    if ($result === false) {
        echo 'Failure: API fetch returned false for ' . $test_record['lis_id'] . "\n";
        $error = true;
        continue;
    }

    foreach ($required_keys as $key) {
        if (!isset($result[$key]) || trim((string)$result[$key]) === '') {
            echo 'Failure: Missing "' . $key . '" for ' . $test_record['lis_id'] . "\n";
            $error = true;
        }
    }

    if (strtolower($result['chamber']) !== $test_record['chamber']) {
        echo 'Failure: Expected chamber ' . $test_record['chamber'] . ' but received ' . $result['chamber'] . "\n";
        $error = true;
    }
}

if (isset($error) && $error === true) {
    return false;
}

echo "All fetch_legislator_data_api tests passed\n";
