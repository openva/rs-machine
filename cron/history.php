<?php

/**
 * Fetch bill status history from the LIS API and persist it to bills_status for the current session.
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

// Get a list of all bills in the current session that have an LIS ID.
$stmt = $pdo->prepare('SELECT
                            id,
                            number,
                            lis_id
                        FROM bills
                        WHERE
                            session_id = :session_id AND
                            lis_id IS NOT NULL');
$stmt->execute([':session_id' => SESSION_ID]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($bills)) {
    $log->put('No bills with LIS IDs found for session ' . SESSION_ID . '; aborting history_api update.', 4);
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
