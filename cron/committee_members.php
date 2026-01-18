<?php

////
//// READ THROUGH THIS CODE BEFORE RUNNING IT
//// You will need to manually mark all the existing committee memberships as ended.
////

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

/*
 * Connect to the database
 */
$database = new Database();
$db = $database->connect();
global $db;
$GLOBALS['dbh'] = $db;

/*
 * Instantiate the logging class
 */
$log = new Log();

$import = new Import($log);

/*
 * Generate a list of committees as a lookup table.
 */
$committees = $import->create_committee_list();

if (empty($committees)) {
    $log->put('Error: No committees found in database.', 8);
    die('Error: No committees found in database.');
}

/*
 * Generate a list of legislators as a lookup table.
 */
$legislators = $import->create_legislator_list();

if (empty($legislators)) {
    $log->put('Error: No legislators found in database.', 8);
    die('Error: No legislators found in database.');
}

/*
 * Prepare session code for API calls
 */
$session_code = '20' . SESSION_LIS_ID;

/*
 * Fetch committee members from the API for all committees
 */
$all_members = [];
$total_members = 0;

foreach ($committees as $committee) {
    // The API expects a numeric committee ID (the lis_id for committees)
    $committee_lis_id = $committee['lis_id'];

    $log->put('Fetching members for committee ' . $committee_lis_id . ' (ID: ' . $committee['id'] . ')', 2);

    // Call the API endpoint
    $url = 'https://lis.virginia.gov/MembersByCommittee/api/getcommitteememberslistasync';
    $query_params = [
        'committeeID' => $committee_lis_id,
        'sessionCode' => $session_code
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($query_params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'WebAPIKey: ' . LIS_KEY,
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($response === false || $http_code >= 400) {
        $log->put('Error: API request failed for committee ' . $committee_lis_id . ' with HTTP ' . $http_code . ': ' . $error, 5);
        continue;
    }

    if ($http_code == 204) {
        $log->put('No members found for committee ' . $committee_lis_id, 3);
        continue;
    }

    $data = json_decode($response, true);

    if ($data === null || !isset($data['Success'])) {
        $log->put('Error: Invalid JSON response for committee ' . $committee_lis_id, 5);
        continue;
    }

    if ($data['Success'] !== true) {
        $log->put('Error: API reported failure for committee ' . $committee_lis_id . ': ' . ($data['FailureMessage'] ?? 'Unknown error'), 5);
        continue;
    }

    if (empty($data['ListItems'])) {
        $log->put('No members found for committee ' . $committee_lis_id, 3);
        continue;
    }

    // Process each member
    foreach ($data['ListItems'] as $member_data) {
        if (empty($member_data['MemberNumber'])) {
            $log->put('Warning: Member found without MemberNumber for committee ' . $committee_lis_id, 4);
            continue;
        }

        // Look up the representative ID from the member number
        $representative_id = $import->lookup_legislator_id($legislators, $member_data['MemberNumber']);

        if ($representative_id === false) {
            $log->put('Warning: Could not find representative for member number ' . $member_data['MemberNumber'], 4);
            continue;
        }

        // Map committee role title to position enum
        $position = null;
        if (!empty($member_data['CommitteeRoleTitle'])) {
            $role = strtolower(trim($member_data['CommitteeRoleTitle']));
            if (stripos($role, 'chair') !== false && stripos($role, 'vice') === false) {
                $position = 'chair';
            } elseif (stripos($role, 'vice') !== false && stripos($role, 'chair') !== false) {
                $position = 'vice chair';
            }
        }

        // Parse assignment date
        $date_started = SESSION_START; // Default to session start
        if (!empty($member_data['AssignDate'])) {
            $parsed_date = date('Y-m-d', strtotime($member_data['AssignDate']));
            if ($parsed_date !== false) {
                $date_started = $parsed_date;
            }
        }

        $all_members[] = [
            'committee_id' => $committee['id'],
            'representative_id' => $representative_id,
            'position' => $position,
            'date_started' => $date_started,
            'member_number' => $member_data['MemberNumber'],
            'member_name' => $member_data['MemberDisplayName'] ?? ''
        ];

        $total_members++;
    }

    $log->put('Found ' . count($data['ListItems']) . ' members for committee ' . $committee_lis_id, 2);
}

/*
 * Validate we have a plausible number of committee memberships
 */
if ($total_members < 300) {
    $log->put('Error: Only ' . $total_members . ' total committee memberships found, which is implausibly low.', 8);
    die('Error: Only ' . $total_members . ' entries were found from the API.');
}

$log->put('Successfully retrieved ' . $total_members . ' total committee memberships from API.', 4);

/*
 * Clear existing committee members for this session (optional - you may want to update instead)
 * For now, we'll just insert new records. You may want to add logic to check for duplicates
 * or clear old data first.
 */

/*
 * Store the new member list using prepared statements
 */
$inserted = 0;
$skipped = 0;

foreach ($all_members as $member) {
    // Check if this membership already exists
    $check_sql = 'SELECT id FROM committee_members
                  WHERE committee_id = :committee_id
                  AND representative_id = :representative_id
                  AND date_ended IS NULL';
    $check_stmt = $GLOBALS['dbh']->prepare($check_sql);
    $check_stmt->execute([
        ':committee_id' => $member['committee_id'],
        ':representative_id' => $member['representative_id']
    ]);

    if ($check_stmt->fetch()) {
        // Membership already exists
        $skipped++;
        continue;
    }

    // Insert new membership
    $sql = 'INSERT INTO committee_members
            SET committee_id = :committee_id,
                representative_id = :representative_id,
                position = :position,
                date_started = :date_started,
                date_created = NOW()';

    $params = [
        ':committee_id' => $member['committee_id'],
        ':representative_id' => $member['representative_id'],
        ':position' => $member['position'],
        ':date_started' => $member['date_started']
    ];

    $stmt = $GLOBALS['dbh']->prepare($sql);
    $result = $stmt->execute($params);

    if ($result) {
        $inserted++;
        $log->put('Added ' . $member['member_name'] . ' (' . $member['member_number'] . ') to committee ' . $member['committee_id'], 3);
    } else {
        $error_info = $stmt->errorInfo();
        $log->put('Failed to add member ' . $member['member_number'] . ' to committee ' . $member['committee_id'] . ': ' . $error_info[2], 5);
    }
}

$log->put('Committee member import complete: ' . $inserted . ' inserted, ' . $skipped . ' skipped (already exist).', 4);
