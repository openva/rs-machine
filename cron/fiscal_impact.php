<?php

use RsMachine\ImpactStatements;

/*
 * Each row of FiscalImpactStatements.csv is one agency's statement on one version of a bill. A
 * new version gets a new reference ID, so it's added alongside the old statement; the website
 * shows only the newest from each agency. A reissued statement has the same reference ID and a
 * new PDF, so it replaces the stored one. See RsMachine\ImpactStatements for the rules.
 */

// Get the CSV
$filename = __DIR__ . '/FiscalImpactStatements.csv';
if (!file_exists($filename) || !is_readable($filename)) {
    exit($filename . ' not found or is not readable');
}
$csv = file_get_contents($filename);

// See if it's CSV, or log an error message when there's no docket file (in the off season)
if (stripos($csv, 'does not exist') !== false) {
    $log->put('Error: FiscalImpactStatements.csv does not contain CSV.', 3);
    return;
}

// LIS sometimes lists a reissued statement more than once, not in publication order, so keep
// only the newest PDF for each.
$statements = ImpactStatements::dedupe(ImpactStatements::parseCsv($csv));

// Look up this session's bill IDs.
$stmt = $dbh->prepare('SELECT number, id FROM bills WHERE session_id = :session_id');
$stmt->execute([':session_id' => SESSION_ID]);
$bill_ids = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$upsert = $dbh->prepare(ImpactStatements::UPSERT_SQL);

$added = 0;
$updated = 0;
$missing_bills = [];

foreach ($statements as $statement) {
    if (!isset($bill_ids[$statement['bill_number']])) {
        $missing_bills[$statement['bill_number']] = true;
        continue;
    }

    $pdf_number = ImpactStatements::pdfNumber($statement['pdf_url']);

    try {
        $upsert->execute([
            ':lis_id'                 => $statement['lis_id'],
            ':bill_id'                => $bill_ids[$statement['bill_number']],
            ':pdf_url'                => $statement['pdf_url'],
            ':pdf_number_for_summary' => $pdf_number,
            ':pdf_number_for_url'     => $pdf_number,
            ':new_pdf_url'            => $statement['pdf_url'],
        ]);
    } catch (PDOException $e) {
        $log->put('Error: Adding fiscal impact statement ' . $statement['lis_id'] . ' for '
            . $statement['bill_number'] . ' failed: ' . $e->getMessage(), 4);
        continue;
    }

    // MySQL reports 1 for an insert, 2 for an update, and 0 when nothing changed.
    if ($upsert->rowCount() == 1) {
        $added++;
        $log->put('Added fiscal impact statement ' . $statement['lis_id'] . ' for '
            . $statement['bill_number'] . '.', 2);
    } elseif ($upsert->rowCount() == 2) {
        $updated++;
        $log->put('Updated fiscal impact statement ' . $statement['lis_id'] . ' for '
            . $statement['bill_number'] . ' to ' . $statement['pdf_url'] . '.', 2);
    }
}

if (count($missing_bills) > 0) {
    $log->put('Fiscal impact statements were not added for ' . count($missing_bills) . ' bills '
        . 'that aren’t in the database: ' . implode(', ', array_keys($missing_bills)) . '.', 3);
}

if ($added || $updated) {
    $log->put('Added ' . $added . ' and updated ' . $updated . ' fiscal impact statements.', 3);
}
