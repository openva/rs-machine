<?php

/**
 * Fetch bill status history from the LIS API and persist it to bills_status for the current
 * session.
 */

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

if (empty($changedLisIds)) {
    $log->put('No bills with changed statuses detected via LIS API; nothing to update.', 2);
    return;
}

// Fetch matching bills from the database for the changed LIS IDs.
$pdo = (new Database())->connect();
if (!$pdo instanceof PDO) {
    $log->put('Unable to connect to database; aborting history update.', 8);
    return false;
}

$placeholders = implode(',', array_fill(0, count($changedLisIds), '?'));
$stmt = $pdo->prepare(
    'SELECT id, number, lis_id
     FROM bills
     WHERE session_id = ? AND lis_id IN (' . $placeholders . ')'
);
$params = array_merge([SESSION_ID], $changedLisIds);
$stmt->execute($params);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($bills)) {
    $log->put('Found ' . count($changedLisIds) . ' changed LIS IDs ('
        . implode(', ', $changedLisIds) . ') that couldn’t be matched to bills in the database; '
        . 'aborting history update.', 2);
    return;
}

$updated = 0;
$failedLisIds = [];

// Iterate through the bills and get the status history for each.
foreach ($bills as $bill) {
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
    $persisted = $import->store_status_history($bill['id'], $normalized_history, SESSION_ID);
    if ($persisted > 0) {
        $updated++;
        if ($mc instanceof Memcached) {
            $mc->delete('bill-' . $bill['id']);
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

$log->put('Updated status history for ' . $updated . ' bills via LIS API.', 2);
