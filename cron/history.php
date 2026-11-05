<?php

/**
 * Fetch bill status history from the LIS API and persist it to bills_status for the current
 * session.
 *
 * A bill's history is fetched when LIS's status for it changes, and also, about once a day, when
 * it hasn't, because many events don't change the status (see RsMachine\HistoryRefresh).
 */

use RsMachine\HistoryRefresh;
use RsMachine\StateFile;

$log = new Log();
$import = new Import($log);

$mc = null;
if (MEMCACHED_SERVER !== '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

// Fetch the current statuses from the LIS API for this session.
$remoteStatuses = $import->get_legislation_session_statuses();
if (empty($remoteStatuses)) {
    $log->put('No session status list returned from LIS API; aborting history update.', 4);
    return;
}

// Build current status map keyed by LegislationID.
$currentById = [];
foreach ($remoteStatuses as $remote) {
    $lisId = $remote['legislation_id'] ?? null;
    if ($lisId === null) {
        continue;
    }

    $status = is_string($remote['status'] ?? null) ? trim($remote['status']) : null;
    $number = is_string($remote['number'] ?? null) ? trim($remote['number']) : null;

    $currentById[(int)$lisId] = [
        'lis_id' => (int)$lisId,
        'number' => $number,
        'status' => $status,
    ];
}

$cacheFile = __DIR__ . '/bill-statuses.json';
$changedLisIds = $import->detect_changed_legislation_statuses($currentById, $cacheFile);

$pdo = (new Database())->connect();
if (!$pdo instanceof PDO) {
    $log->put('Unable to connect to database; aborting history update.', 8);
    return false;
}

// This session's bills, keyed by LIS legislation ID.
$stmt = $pdo->prepare('SELECT id, number, lis_id FROM bills WHERE session_id = ? AND lis_id IS NOT NULL');
$stmt->execute([SESSION_ID]);
$billsByLisId = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bill) {
    $billsByLisId[(int) $bill['lis_id']] = $bill;
}

// LIS lists a new bill hours before BILLS.CSV does, so a changed bill may not be in the database
// yet. Leave its status out of the cache, so that it still counts as changed once it's added.
$unmatched = array_diff($changedLisIds, array_keys($billsByLisId));
foreach ($unmatched as $lisId) {
    unset($currentById[$lisId]);
}
if ($unmatched !== []) {
    $log->put(count($unmatched) . ' bills whose status changed aren’t in the database yet, so their '
        . 'history will be fetched once they are.', 2);
}
$changedLisIds = array_values(array_intersect($changedLisIds, array_keys($billsByLisId)));

// Also refetch a few bills whose status hasn't changed, those fetched longest ago.
$fetchedFile = __DIR__ . '/bill-history-fetched.json';
$lastFetched = StateFile::load($fetchedFile);
$refreshLisIds = HistoryRefresh::due(
    $lastFetched,
    array_diff(array_keys($billsByLisId), $changedLisIds),
    time()
);

$count = $pdo->prepare('SELECT COUNT(*) FROM bills_status WHERE bill_id = ?');

$updated = 0;
$failedLisIds = [];

// Get each bill's status history.
$changed = array_flip($changedLisIds);
foreach (array_merge($changedLisIds, $refreshLisIds) as $lisId) {
    $bill = $billsByLisId[$lisId];

    // Get this bill's status history from the LIS API.
    $raw_history = $import->get_bill_status_history($bill['lis_id']);
    if (empty($raw_history)) {
        $log->put('No status history returned for ' . strtoupper($bill['number']) . ' (LegislationID ' . $bill['lis_id'] . ')', 2);
        $failedLisIds[] = (int) $bill['lis_id'];
        continue;
    }

    // Get the bits of the bill's status history that we actually want.
    $normalized_history = $import->normalize_status_history($raw_history);
    if (empty($normalized_history)) {
        $log->put('Normalized history empty for ' . strtoupper($bill['number']) . '; skipping.', 2);
        $failedLisIds[] = (int) $bill['lis_id'];
        continue;
    }

    // Store the status history in the database.
    $count->execute([$bill['id']]);
    $before = (int) $count->fetchColumn();
    $persisted = $import->store_status_history($bill['id'], $normalized_history, SESSION_ID);
    if ($persisted > 0) {
        $lastFetched[$lisId] = time();
        $count->execute([$bill['id']]);
        if (isset($changed[$lisId]) || (int) $count->fetchColumn() !== $before) {
            $updated++;
            if ($mc instanceof Memcached) {
                $mc->delete('bill-' . $bill['id']);
            }
        }
    } else {
        $log->put('Failed to store status history for ' . strtoupper($bill['number']), 4);
        $failedLisIds[] = (int) $bill['lis_id'];
    }
}

// Remove failed bills from the cache so they are retried on the next run.
foreach ($failedLisIds as $failedId) {
    unset($currentById[$failedId]);
}

$import->write_status_cache($currentById, $cacheFile);
if (!StateFile::save($fetchedFile, $lastFetched, array_keys($billsByLisId))) {
    $log->put('Unable to write ' . $fetchedFile, 5);
}

$log->put('Fetched the history of ' . count($changedLisIds) . ' bills whose status changed and '
    . count($refreshLisIds) . ' whose status didn’t; ' . $updated . ' were updated.', 2);
