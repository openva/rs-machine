<?php

/**
 * Test that fetch_photo() correctly downloads legislator photos and rejects HTML responses.
 */

include_once(__DIR__ . '/../../includes/settings.inc.php');
include_once(__DIR__ . '/../../includes/class.Database.php');
include_once(__DIR__ . '/../../includes/class.Log.php');
include_once(__DIR__ . '/../../includes/class.Import.php');

$log = new Log();
$import = new Import($log);
$error = false;

/*
 * Test 1: A known House photo URL returns an image file, not false.
 */
$house_url = 'https://memdata.virginiageneralassembly.gov/images/display_image/H0314';
$result = $import->fetch_photo($house_url, '_fetch_photos_test_house');
if ($result === false) {
    echo 'Failure: fetch_photo() returned false for a known House photo URL.' . "\n";
    $error = true;
} else {
    if (file_exists($result)) {
        unlink($result);
    }
}

/*
 * Test 2: A URL that returns HTML is rejected — returns false and leaves no file on disk.
 */
$html_url = 'https://memdata.virginiageneralassembly.gov/images/display_image/H9999';
$result = $import->fetch_photo($html_url, '_fetch_photos_test_html');
if ($result !== false) {
    echo 'Failure: fetch_photo() should have returned false for an HTML response, but returned: ' . $result . "\n";
    $error = true;
    if (file_exists($result)) {
        unlink($result);
    }
}

if ($error === true) {
    return false;
}

echo 'All fetch_photos tests passed' . "\n";
