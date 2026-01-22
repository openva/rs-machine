#!/usr/bin/env php
<?php

/**
 * One-time repair script to retroactively populate the chamber column in bills_status.
 *
 * Logic:
 * 1. Only operates on bills and joint resolutions (HB, SB, HJ, SJ)
 * 2. Determines originating chamber from bill number prefix (HB/HJ = house, SB/SJ = senate)
 * 3. Finds "passed house" or "passed senate" in the translation column
 * 4. All statuses before (and including) that passage are tagged with originating chamber
 * 5. All statuses after that passage are tagged with the opposite chamber
 * 6. Handles multiple passages (chamber can flip back and forth)
 * 7. Bills that never pass either chamber get all statuses tagged with originating chamber
 * 8. Operates on all sessions
 *
 * Usage: php deploy/repair_chamber_status.php [--dry-run]
 */

require_once __DIR__ . '/../includes/settings.inc.php';

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);

if ($dryRun) {
    echo "DRY RUN MODE: No changes will be made to the database.\n";
}

// Connect to database
// Handle both Docker environment (PDO_DSN) and production (DB_* constants)
if (defined('PDO_DSN')) {
    $dsn = PDO_DSN;
    $username = defined('PDO_USERNAME') ? PDO_USERNAME : 'ricsun';
    $password = defined('PDO_PASSWORD') ? PDO_PASSWORD : 'password';
} else {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_DATABASE . ';charset=utf8';
    $username = DB_USERNAME;
    $password = DB_PASSWORD;
}

$pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET NAMES utf8");

echo "Connected to database.\n\n";

// Step 1: Add chamber column if it doesn't exist
echo "Step 1: Checking if chamber column exists...\n";
$stmt = $pdo->query("SHOW COLUMNS FROM bills_status LIKE 'chamber'");
$columnExists = $stmt->rowCount() > 0;

if (!$columnExists) {
    echo "Chamber column does not exist. Adding it...\n";
    if (!$dryRun) {
        $pdo->exec("
            ALTER TABLE bills_status
            ADD COLUMN chamber ENUM('house','senate') DEFAULT NULL
            AFTER status
        ");
        echo "Added chamber column.\n";
    } else {
        echo "[DRY RUN] Would add chamber column.\n";
    }
} else {
    echo "Chamber column already exists.\n";
}

// Step 2: Get all bills (only HB, SB, HJ, SJ)
echo "\nStep 2: Fetching bills...\n";
$billsStmt = $pdo->query("
    SELECT id, number
    FROM bills
    WHERE number REGEXP '^(hb|sb|hj|sj)[0-9]+$'
    ORDER BY id
");
$bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($bills) . " bills/joint resolutions to process.\n";

// Step 3: Process each bill
echo "\nStep 3: Processing bills...\n";

$updateCount = 0;
$billsProcessed = 0;
$errors = [];

$updateStmt = $pdo->prepare("
    UPDATE bills_status
    SET chamber = :chamber
    WHERE id = :id
");

foreach ($bills as $bill) {
    $billId = $bill['id'];
    $billNumber = $bill['number'];

    // Determine originating chamber from bill number prefix
    $prefix = substr($billNumber, 0, 2);
    $originatingChamber = in_array($prefix, ['hb', 'hj']) ? 'house' : 'senate';
    $otherChamber = ($originatingChamber === 'house') ? 'senate' : 'house';

    // Get all statuses for this bill, ordered by date
    $statusStmt = $pdo->prepare("
        SELECT id, status, translation, date
        FROM bills_status
        WHERE bill_id = :bill_id
        ORDER BY date ASC, id ASC
    ");
    $statusStmt->execute([':bill_id' => $billId]);
    $statuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($statuses)) {
        continue;
    }

    // Track current chamber (starts with originating)
    $currentChamber = $originatingChamber;

    // Process each status
    foreach ($statuses as $status) {
        $statusId = $status['id'];
        $translation = strtolower($status['translation'] ?? '');

        // Check if this status indicates a chamber passage
        if (strpos($translation, 'passed house') !== false) {
            // This passage happened in the house, so we're still in house for this status
            $chamberForThisStatus = 'house';
            // After this status, we transition to senate
            $currentChamber = 'senate';
        } elseif (strpos($translation, 'passed senate') !== false) {
            // This passage happened in the senate, so we're still in senate for this status
            $chamberForThisStatus = 'senate';
            // After this status, we transition to house
            $currentChamber = 'house';
        } else {
            // No passage indicator, use current chamber
            $chamberForThisStatus = $currentChamber;
        }

        // Update the status record
        if (!$dryRun) {
            try {
                $updateStmt->execute([
                    ':chamber' => $chamberForThisStatus,
                    ':id' => $statusId
                ]);
                $updateCount++;
            } catch (Exception $e) {
                $errors[] = "Error updating status ID {$statusId} for bill {$billNumber}: " . $e->getMessage();
            }
        } else {
            $updateCount++;
        }
    }

    $billsProcessed++;

    // Progress indicator every 100 bills
    if ($billsProcessed % 100 === 0) {
        echo "Processed {$billsProcessed} bills...\n";
    }
}

echo "\nStep 4: Summary\n";
echo "===============\n";
echo "Bills processed: {$billsProcessed}\n";
echo "Status records updated: {$updateCount}\n";

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

if ($dryRun) {
    echo "\n[DRY RUN] No changes were made to the database.\n";
} else {
    echo "\nRepair completed successfully!\n";

    // Step 5: Add index for chamber column
    echo "\nStep 5: Adding index on chamber column...\n";
    try {
        $pdo->exec("
            ALTER TABLE bills_status
            ADD KEY chamber (chamber)
        ");
        echo "Added index on chamber column.\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "Index already exists, skipping.\n";
        } else {
            echo "Error adding index: " . $e->getMessage() . "\n";
        }
    }

    // Step 6: Show sample results
    echo "\nStep 6: Verification - Sample results:\n";
    echo "=======================================\n";

    $sampleStmt = $pdo->query("
        SELECT b.number, bs.status, bs.translation, bs.chamber, bs.date
        FROM bills_status bs
        JOIN bills b ON bs.bill_id = b.id
        WHERE b.number IN ('hb1', 'sb1', 'hb100', 'sb100')
        ORDER BY b.number, bs.date
        LIMIT 20
    ");

    $samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($samples as $sample) {
        printf(
            "%s | %s | %s | %s\n",
            str_pad($sample['number'], 6),
            str_pad($sample['chamber'] ?? 'NULL', 6),
            str_pad(substr($sample['date'], 0, 10), 10),
            substr($sample['translation'] ?? $sample['status'], 0, 60)
        );
    }

    echo "\nTo verify the results, run:\n";
    echo "  SELECT chamber, COUNT(*) FROM bills_status GROUP BY chamber;\n";
}

echo "\nDone.\n";
