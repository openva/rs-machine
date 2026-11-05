<?php

/*
 * Set bills' introduction dates to those in LIS's BILLS.CSV.
 *
 * Until November 2026, bills.php ignored the CSV's Introduction_date column, and cleanup.php used
 * the date we first imported each bill, often days late. bills.php now stores LIS's date, but only
 * for bills whose row in the CSV changes, so this corrects the rest. LIS publishes BILLS.CSV for
 * 2024 and later. It's safe to rerun.
 *
 * Usage: php utilities/update_introduction_dates.php <session ID> [<session ID> ...]
 */

use RsMachine\BillsCsv;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

$session_ids = array_map('intval', array_slice($argv, 1));
if ($session_ids === [] || in_array(0, $session_ids, true)) {
    fwrite(STDERR, "Usage: php utilities/update_introduction_dates.php <session ID> [<session ID> ...]\n");
    exit(1);
}

$dbh = (new Database())->connect();
if (!$dbh instanceof PDO) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mc = null;
if (defined('MEMCACHED_SERVER') && MEMCACHED_SERVER !== '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, (int) MEMCACHED_PORT);
}

$session = $dbh->prepare('SELECT lis_id FROM sessions WHERE id = :id');
$bills = $dbh->prepare('SELECT number, id, date_introduced FROM bills WHERE session_id = :session_id');
$update = $dbh->prepare('UPDATE bills SET date_introduced = :date WHERE id = :id');

$failed = false;
foreach ($session_ids as $session_id) {
    $session->execute([':id' => $session_id]);
    $lis_id = $session->fetchColumn();
    if (!$lis_id) {
        fwrite(STDERR, 'Session ' . $session_id . " has no LIS ID.\n");
        $failed = true;
        continue;
    }

    $url = 'https://lis.blob.core.windows.net/lisfiles/20' . $lis_id . '/BILLS.CSV';
    $csv = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60]]));
    if ($csv === false || trim($csv) === '') {
        fwrite(STDERR, 'Session ' . $session_id . ': could not download ' . $url . "\n");
        $failed = true;
        continue;
    }

    $bills->execute([':session_id' => $session_id]);
    $stored = [];
    foreach ($bills->fetchAll(PDO::FETCH_ASSOC) as $bill) {
        $stored[$bill['number']] = $bill;
    }

    $changed = 0;
    $undated = 0;
    $lines = explode("\n", trim($csv));
    array_shift($lines);
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',', '"');
        $number = strtolower(trim($row[0] ?? ''));
        if (!isset($stored[$number])) {
            continue;
        }

        $date = BillsCsv::introductionDate($row);
        if ($date === null) {
            $undated++;
            continue;
        }
        if ($stored[$number]['date_introduced'] === $date) {
            continue;
        }

        $update->execute([':date' => $date, ':id' => $stored[$number]['id']]);
        $mc?->delete('bill-' . $stored[$number]['id']);
        $changed++;
    }

    echo 'Session ' . $session_id . ': corrected the introduction dates of ' . $changed . ' of '
        . count($stored) . ' bills' . ($undated > 0 ? '; LIS gives no date for ' . $undated : '') . ".\n";
}

exit($failed ? 1 : 0);
