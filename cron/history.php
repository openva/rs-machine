<?php

/**
 * Fetch bill status history from the LIS API and persist it to bills_status for the current
 * session.
 */

$log = new Log();
$import = new Import($log);

$pdo = (new Database())->connect();
if (!$pdo instanceof PDO) {
    $log->put('Unable to connect to database; aborting history_api update.', 8);
    exit(1);
}

$mc = null;
if (MEMCACHED_SERVER !== '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

// Get a list of bills in the current session (with their latest stored status).
$stmt = $pdo->prepare(
    'SELECT
        b.id,
        b.number,
        b.lis_id,
        latest.status AS current_status
    FROM bills b
    LEFT JOIN (
        SELECT bs_outer.bill_id, bs_outer.status
        FROM bills_status bs_outer
        WHERE bs_outer.session_id = :session_id
          AND bs_outer.id = (
              SELECT bs_inner.id
              FROM bills_status bs_inner
              WHERE bs_inner.bill_id = bs_outer.bill_id
                AND bs_inner.session_id = :session_id
              ORDER BY bs_inner.date DESC, bs_inner.id DESC
              LIMIT 1
          )
    ) AS latest ON latest.bill_id = b.id
    WHERE
        b.session_id = :session_id AND
        b.lis_id IS NOT NULL'
);
$stmt->execute([':session_id' => SESSION_ID]);
$allBills = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($allBills)) {
    $log->put('No bills with LIS IDs found for session ' . SESSION_ID . '; aborting history update.', 4);
    return;
}

// Fetch the current statuses from the LIS API for this session.
$remoteStatuses = $import->get_legislation_session_statuses();
if (empty($remoteStatuses)) {
    $log->put('No session status list returned from LIS API; aborting history update.', 4);
    return;
}

// Index local bills by LIS ID for quick comparison.
$localByLisId = [];
foreach ($allBills as $bill) {
    $localByLisId[(int)$bill['lis_id']] = $bill;
}

// Determine which bills have changed statuses according to the API.
$bills = [];
foreach ($remoteStatuses as $remote) {
    $lisId = (int)($remote['legislation_id'] ?? 0);
    if (!isset($localByLisId[$lisId])) {
        continue;
    }

    $apiStatus = is_string($remote['status'] ?? null) ? trim($remote['status']) : null;
    $localStatus = is_string($localByLisId[$lisId]['current_status'] ?? null)
        ? trim($localByLisId[$lisId]['current_status'])
        : null;

    if ($apiStatus !== $localStatus) {
        $bills[] = $localByLisId[$lisId];
    }
}

if (empty($bills)) {
    $log->put('No bills with changed statuses detected via LIS API; nothing to update.', 2);
    return;
}

$updated = 0;

// Iterate through the bills and get the status history for each.
foreach ($bills as $bill) {
    // Get this bill's status history from the LIS API.
    $raw_history = $import->get_bill_status_history($bill['lis_id']);
    if (empty($raw_history)) {
        $log->put('No status history returned for ' . strtoupper($bill['number']) . ' (LegislationID ' . $bill['lis_id'] . ')', 2);
        continue;
    }

    // Get the bits of the bill's status history that we actually want.
    $normalized_history = $import->normalize_status_history($raw_history);
    if (empty($normalized_history)) {
        $log->put('Normalized history empty for ' . strtoupper($bill['number']) . '; skipping.', 2);
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
    }
}

$log->put('Updated status history for ' . $updated . ' bills via LIS API.', 2);
