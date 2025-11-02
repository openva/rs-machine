<?php

include_once(__DIR__ . '/../../includes/settings.inc.php');
include_once(__DIR__ . '/../../includes/functions.inc.php');
include_once(__DIR__ . '/../../includes/vendor/autoload.php');

$lis_key = defined('LIS_KEY') ? trim(LIS_KEY) : '';
if ($lis_key === '') {
    echo "Skipping bill text tests (LIS_KEY not configured).\n";
    return false;
}

$log = new Log();
$import = new Import($log);

$tests = [
    [
        'document' => 'HB1',
        'session' => SESSION_LIS_ID,
        'expect_success' => true,
        'expect_phrase' => 'Be it enacted by the General Assembly of Virginia',
    ],
    [
        'document' => 'ZZ9999',
        'session' => SESSION_LIS_ID,
        'expect_success' => false,
        'expected_status' => 'no_text',
    ],
];

$error = false;

foreach ($tests as $test) {
    $result = $import->fetch_bill_text_from_api($test['document'], $test['session']);

    if ($test['expect_success'] === true) {
        if ($result['success'] !== true) {
            echo 'Failure: Expected success retrieving ' . $test['document']
                . ', but received status "' . ($result['status'] ?? 'unknown') . "\".\n";
            $error = true;
            continue;
        }

        $text = $result['text'] ?? '';
        if (trim($text) === '') {
            echo 'Failure: Retrieved text for ' . $test['document'] . " is empty.\n";
            $error = true;
            continue;
        }

        if (!empty($test['expect_phrase']) && stripos($text, $test['expect_phrase']) === false) {
            echo 'Failure: Expected to find phrase "' . $test['expect_phrase']
                . '" within text for ' . $test['document'] . ".\n";
            $error = true;
        }
    } else {
        if ($result['success'] === true) {
            echo 'Failure: Expected failure retrieving ' . $test['document']
                . ', but the request succeeded.' . "\n";
            $error = true;
            continue;
        }

        if (
            isset($test['expected_status'])
            && ($result['status'] ?? '') !== $test['expected_status']
        ) {
            echo 'Failure: Expected status "' . $test['expected_status'] . '" for '
                . $test['document'] . ', but received "'
                . ($result['status'] ?? 'unknown') . "\".\n";
            $error = true;
        }
    }
}

if ($error === true) {
    return false;
}

echo "All bill text tests passed\n";
return true;
