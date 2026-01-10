<?php

###
# Test script for get_crossover_date() function
###

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';

$_SERVER['argc'] = 2;
$_SERVER['argv'] = ['update.php', 'bills_status_narratives'];
require_once __DIR__ . '/../../cron/update.php';

$dbh = new Database();
$db = $dbh->connect_mysqli();

echo "Testing get_crossover_date() function...\n\n";

// Test 1: Get crossover date for 2024 session (should exist)
echo "Test 1: Getting crossover date for 2024...\n";
$crossover_2024 = get_crossover_date($db, 2024);
if ($crossover_2024 !== null) {
    echo "✓ Success: Found crossover date for 2024: {$crossover_2024}\n";
} else {
    echo "✗ Warning: No crossover date found for 2024\n";
}
echo "\n";

// Test 2: Get crossover date for 2025 session
echo "Test 2: Getting crossover date for 2025...\n";
$crossover_2025 = get_crossover_date($db, 2025);
if ($crossover_2025 !== null) {
    echo "✓ Success: Found crossover date for 2025: {$crossover_2025}\n";
} else {
    echo "✗ Warning: No crossover date found for 2025\n";
}
echo "\n";

// Test 3: Get crossover date for a year that doesn't exist (should return null)
echo "Test 3: Getting crossover date for 1999 (should not exist)...\n";
$crossover_1999 = get_crossover_date($db, 1999);
if ($crossover_1999 === null) {
    echo "✓ Success: Correctly returned null for non-existent year\n";
} else {
    echo "✗ Failure: Expected null but got: {$crossover_1999}\n";
}
echo "\n";

// Test 4: Query sessions table to show what's available
echo "Test 4: Listing all sessions with crossover dates...\n";
$sql = 'SELECT year, crossover FROM sessions WHERE crossover IS NOT NULL ORDER BY year DESC';
$result = mysqli_query($db, $sql);
if ($result && mysqli_num_rows($result) > 0) {
    echo "Available sessions with crossover dates:\n";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "  - Year {$row['year']}: {$row['crossover']}\n";
    }
} else {
    echo "No sessions with crossover dates found in database.\n";
}
echo "\n";

echo "All tests completed.\n";

mysqli_close($db);
