<?php

/*
 * Merge bills' history entries that are stored more than once (the same text on the same day),
 * keeping the first recorded, with LIS's latest time. See RsMachine\StatusHistoryDuplicates.
 *
 * Without --apply, it only reports what it would merge. Run it before
 * `run.php --session=<ID> cleanup`, whose narrative check takes a copy for a new status.
 *
 * Usage: php utilities/merge_status_duplicates.php [--apply] [<session ID> ...]
 *        (all sessions if none are given)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

use RsMachine\BillCache;
use RsMachine\StatusHistoryDuplicates;

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$args = array_values(array_diff($args, ['--apply']));
$session_ids = array_map('intval', $args);
if (in_array(0, $session_ids, true)) {
    fwrite(STDERR, "Usage: php utilities/merge_status_duplicates.php [--apply] [<session ID> ...]\n");
    exit(1);
}

$dbh = (new Database())->connect();
if (!$dbh instanceof PDO) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mc = null;
if ($apply && class_exists('Memcached') && defined('MEMCACHED_SERVER') && MEMCACHED_SERVER !== '') {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, defined('MEMCACHED_PORT') ? MEMCACHED_PORT : 11211);
}

foreach ($session_ids === [] ? [null] : $session_ids as $session_id) {
    $label = $session_id === null ? 'All sessions' : 'Session ' . $session_id;
    $entries = StatusHistoryDuplicates::find($dbh, $session_id);
    $copies = 0;
    foreach ($entries as $copiesOfEntries) {
        foreach ($copiesOfEntries as $entry) {
            $copies += count($entry) - 1;
        }
    }
    $count = array_sum(array_map('count', $entries));
    echo $label . ': ' . $count . ' entries of ' . count($entries) . ' bills are stored more than once, '
        . 'with ' . $copies . " extra copies.\n";

    if (!$apply || $entries === []) {
        continue;
    }
    $removed = StatusHistoryDuplicates::apply($dbh, $entries);
    BillCache::forget($mc, array_keys($entries));
    echo $label . ': merged them, removing ' . $removed . " rows.\n";
}

if (!$apply) {
    echo "Nothing was changed. Run again with --apply to merge them.\n";
}
