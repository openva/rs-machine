<?php

/**
 * One-time repair: replace stored fiscal impact statements with the newest PDF that LIS lists.
 *
 * Until cron/fiscal_impact.php deduplicated FiscalImpactStatements.csv, a statement that LIS had
 * reissued was stored with whichever PDF came last in the CSV, which was often the older one.
 * This re-reads the current session's CSV, applies the same rule (the highest-numbered PDF for
 * each bill and reference ID), and updates stored statements that have an older PDF, clearing
 * their summaries so that the new statement is summarized. Statements that aren't stored yet are
 * left for the cron job to add.
 *
 * Usage:
 *   php utilities/repair_fiscal_impact.php               Report what would change (dry run).
 *   php utilities/repair_fiscal_impact.php --apply       Make the changes.
 *   php utilities/repair_fiscal_impact.php --csv=FILE    Read FILE instead of downloading the
 *                                                        current session's CSV from LIS.
 */

declare(strict_types=1);

use RsMachine\ImpactStatements;

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

$options = getopt('', ['apply', 'csv:']);
$apply = isset($options['apply']);

if (isset($options['csv'])) {
    $source = $options['csv'];
} else {
    $source = 'https://lis.blob.core.windows.net/lisfiles/20' . SESSION_LIS_ID
        . '/FiscalImpactStatements.csv';
}
$csv = @file_get_contents($source);
if ($csv === false || stripos($csv, 'does not exist') !== false) {
    fwrite(STDERR, 'Could not read ' . $source . "\n");
    exit(1);
}

$rows = ImpactStatements::parseCsv($csv);
$newest = ImpactStatements::dedupe($rows);
$listings = array_count_values(array_map(fn($r) => $r['bill_number'] . '|' . $r['lis_id'], $rows));
$reissued = count(array_filter($listings, fn($n) => $n > 1));

$dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $dbh->prepare('SELECT
        fiscal_impact_statements.id,
        bills.number,
        fiscal_impact_statements.lis_id,
        fiscal_impact_statements.pdf_url,
        fiscal_impact_statements.summary IS NOT NULL AS has_summary
    FROM fiscal_impact_statements
    JOIN bills
        ON bills.id = fiscal_impact_statements.bill_id
    WHERE bills.session_id = :session_id');
$stmt->execute([':session_id' => SESSION_ID]);
$stored = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stored[strtolower($row['number']) . '|' . $row['lis_id']] = $row;
}

$replace = [];      // The stored PDF is older than the newest in the CSV.
$move = [];         // The same PDF, at a new URL.
$stored_newer = 0;  // The stored PDF is newer than any in the CSV; left alone.
$not_stored = 0;

foreach ($newest as $statement) {
    $key = $statement['bill_number'] . '|' . $statement['lis_id'];
    if (!isset($stored[$key])) {
        $not_stored++;
        continue;
    }

    $row = $stored[$key];
    $new_number = ImpactStatements::pdfNumber($statement['pdf_url']);
    $old_number = ImpactStatements::pdfNumber($row['pdf_url']);

    if ($new_number > $old_number) {
        $replace[] = $row + ['new_pdf_url' => $statement['pdf_url']];
    } elseif ($new_number == $old_number && $statement['pdf_url'] !== $row['pdf_url']) {
        $move[] = $row + ['new_pdf_url' => $statement['pdf_url']];
    } elseif ($new_number < $old_number) {
        $stored_newer++;
    }
}

/*
 * Report.
 */
echo ($apply ? 'Repairing' : 'Dry run (pass --apply to make these changes)') . "\n";
echo 'Source: ' . $source . "\n";
echo 'Session: ' . SESSION_ID . "\n\n";
echo 'CSV rows:                               ' . count($rows) . "\n";
echo 'Statements (unique bill + reference ID): ' . count($newest) . "\n";
echo 'Reissued (listed with more than one PDF): ' . $reissued . "\n";
echo 'Stored statements for this session:     ' . count($stored) . "\n";
echo 'Not stored yet (left for the cron job): ' . $not_stored . "\n";
echo 'Stored PDF is newer than the CSV’s:     ' . $stored_newer . "\n";
echo 'Same PDF at a new URL (summary kept):   ' . count($move) . "\n";
echo 'Older PDF to replace:                   ' . count($replace) . "\n";
echo '  of which have a summary to clear:     '
    . count(array_filter($replace, fn($r) => (bool) $r['has_summary'])) . "\n";

if (count($replace) > 0) {
    echo "\nStored statements with an older PDF:\n\n";
    printf("%-8s %-14s %-9s %-9s %s\n", 'Bill', 'Reference ID', 'Stored', 'Newest', 'Summary');
    foreach ($replace as $row) {
        printf(
            "%-8s %-14s %-9s %-9s %s\n",
            strtoupper($row['number']),
            $row['lis_id'],
            ImpactStatements::pdfNumber($row['pdf_url']),
            ImpactStatements::pdfNumber($row['new_pdf_url']),
            $row['has_summary'] ? 'cleared' : '-'
        );
    }
}

foreach ($move as $row) {
    echo "\nMoved: " . strtoupper($row['number']) . ' ' . $row['lis_id'] . ' '
        . $row['pdf_url'] . ' -> ' . $row['new_pdf_url'];
}
if (count($move) > 0) {
    echo "\n";
}

if (!$apply) {
    exit(0);
}

/*
 * Apply. Each update applies only if the stored PDF hasn't changed since it was read above.
 */
$update = $dbh->prepare('UPDATE fiscal_impact_statements
    SET
        summary = IF(:clear_summary, NULL, summary),
        pdf_url = :new_pdf_url
    WHERE
        id = :id AND
        pdf_url <=> :old_pdf_url');

$changed = 0;
$dbh->beginTransaction();
foreach ([[$replace, 1], [$move, 0]] as [$rows_to_change, $clear_summary]) {
    foreach ($rows_to_change as $row) {
        $update->execute([
            ':clear_summary' => $clear_summary,
            ':new_pdf_url'   => $row['new_pdf_url'],
            ':id'            => $row['id'],
            ':old_pdf_url'   => $row['pdf_url'],
        ]);
        $changed += $update->rowCount();
    }
}
$dbh->commit();

echo "\nUpdated " . $changed . ' of ' . (count($replace) + count($move)) . " statements.\n";
