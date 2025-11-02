<?php

include_once(__DIR__ . '/../../includes/settings.inc.php');
include_once(__DIR__ . '/../../includes/functions.inc.php');
include_once(__DIR__ . '/../../includes/vendor/autoload.php');

$lis_key = defined('LIS_KEY') ? trim(LIS_KEY) : '';
if ($lis_key === '') {
    echo "Skipping bill PDF tests (LIS_KEY not configured).\n";
    return false;
}

$log = new Log();
$import = new Import($log);

$tmpDir = sys_get_temp_dir() . '/rs_machine_pdf_tests';
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    echo "Failure: Could not create temporary directory {$tmpDir}\n";
    return false;
}

$error = false;

// Test retrieving a known bill PDF
$pdfPath = $tmpDir . '/hb1.pdf';
if (file_exists($pdfPath)) {
    unlink($pdfPath);
}

$import->bill_number = 'HB1';
$import->document_number = null;
$import->lis_session_id = SESSION_LIS_ID;

$result = $import->get_bill_pdf_api($pdfPath);

if ($result === false || !file_exists($pdfPath)) {
    echo "Failure: Expected to download HB1 PDF but the file was not created.\n";
    $error = true;
} else {
    $filesize = filesize($pdfPath);
    if ($filesize === false || $filesize === 0) {
        echo "Failure: Downloaded HB1 PDF is empty.\n";
        $error = true;
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $pdfPath) : null;
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($mimeType !== 'application/pdf') {
            echo 'Failure: Downloaded HB1 file is not a PDF (detected MIME type: '
                . ($mimeType ?? 'unknown') . ").\n";
            $error = true;
        } else {
            $handle = fopen($pdfPath, 'rb');
            $prefix = $handle ? fread($handle, 4) : false;
            if ($handle) {
                fclose($handle);
            }
            if ($prefix === false || $prefix !== "%PDF") {
                echo "Failure: Downloaded HB1 file does not start with %PDF signature.\n";
                $error = true;
            }
        }
    }
}

if (file_exists($pdfPath)) {
    unlink($pdfPath);
}

// Test handling of a nonexistent bill/document
$import->bill_number = 'ZZ9999';
$import->document_number = null;
$import->lis_session_id = SESSION_LIS_ID;
$pdfPathInvalid = $tmpDir . '/zz9999.pdf';

$resultInvalid = $import->get_bill_pdf_api($pdfPathInvalid);
if ($resultInvalid !== false) {
    echo "Failure: Expected download failure for ZZ9999 but call succeeded.\n";
    $error = true;
}
if (file_exists($pdfPathInvalid)) {
    echo "Failure: PDF file for nonexistent bill ZZ9999 was unexpectedly created.\n";
    unlink($pdfPathInvalid);
    $error = true;
}

@rmdir($tmpDir);

if ($error === true) {
    return false;
}

echo "All bill PDF tests passed\n";
return true;
