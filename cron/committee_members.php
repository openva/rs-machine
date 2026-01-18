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
 * Prepare session code for API calls
 */
$session_code = '20' . SESSION_LIS_ID;

/*
 * Step 1: Fetch all committees from the Committee API to get the CommitteeID mapping
 */
$log->put('Fetching committee list from Committee API...', 3);

$api_committees = [];
foreach (['H', 'S'] as $chamber_code) {
    $url = 'https://lis.virginia.gov/Committee/api/getcommitteelistasync';
    $query_params = [
        'sessionID' => SESSION_ID,
        'chamberCode' => $chamber_code,
        'includeSubCommittees' => false
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
    curl_close($ch);

    if ($response === false || $http_code >= 400) {
        $log->put('Error: Committee API request failed for chamber ' . $chamber_code . ' with HTTP ' . $http_code . ': ' . $error, 8);
        die('Error: Could not fetch committee list from API.');
    }

    if ($http_code == 204) {
        $log->put('No committees found for chamber ' . $chamber_code, 4);
        continue;
    }

    $data = json_decode($response, true);

    if ($data === null || !isset($data['Success'])) {
        $log->put('Error: Invalid JSON response from Committee API for chamber ' . $chamber_code, 8);
        die('Error: Invalid response from Committee API.');
    }

    if ($data['Success'] !== true) {
        $log->put('Error: Committee API reported failure for chamber ' . $chamber_code . ': ' . ($data['FailureMessage'] ?? 'Unknown error'), 8);
        die('Error: Committee API reported failure.');
    }

    if (!empty($data['ListItems'])) {
        foreach ($data['ListItems'] as $committee) {
            // Store mapping of CommitteeNumber to CommitteeID
            if (!empty($committee['CommitteeNumber']) && isset($committee['CommitteeID'])) {
                $api_committees[$committee['CommitteeNumber']] = $committee['CommitteeID'];
            }
        }
    }
}

$log->put('Retrieved ' . count($api_committees) . ' committees from API.', 3);

if (count($api_committees) < 20) {
    $log->put('Error: Too few committees retrieved from API (' . count($api_committees) . ').', 8);
    die('Error: Too few committees retrieved from API.');
}

/*
 * Step 2: Generate a list of committees from local database
 */
$committees = $import->create_committee_list();

if (empty($committees)) {
    $log->put('Error: No committees found in database.', 8);
    die('Error: No committees found in database.');
}

/*
 * Generate a list of legislators as a lookup table
 */
$legislators = $import->create_legislator_list();

if (empty($legislators)) {
    $log->put('Error: No legislators found in database.', 8);
    die('Error: No legislators found in database.');
}

/*
 * Step 3: Fetch committee members from the API for all committees
 */
$all_members = [];
$total_members = 0;

foreach ($committees as $committee) {
    // Build the committee number from chamber and lis_id
    $chamber_prefix = strtoupper($committee['chamber']) === 'HOUSE' ? 'H' : 'S';
    $committee_number = $chamber_prefix . str_pad($committee['lis_id'], 2, '0', STR_PAD_LEFT);

    // Look up the API's CommitteeID for this committee
    if (!isset($api_committees[$committee_number])) {
        $log->put('Warning: Committee ' . $committee_number . ' (DB ID: ' . $committee['id'] . ') not found in API response.', 4);
        continue;
    }

    $committee_api_id = $api_committees[$committee_number];

    $log->put('Fetching members for committee ' . $committee_number . ' (API ID: ' . $committee_api_id . ', DB ID: ' . $committee['id'] . ')', 2);

    // Call the MembersByCommittee API endpoint
    $url = 'https://lis.virginia.gov/MembersByCommittee/api/getcommitteememberslistasync';
    $query_params = [
        'committeeID' => (int)$committee_api_id,
        'sessionCode' => (int)$session_code,
        'sessionID' => SESSION_ID
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
        $log->put('Error: API request failed for committee ' . $committee_number . ' with HTTP ' . $http_code . ': ' . $error, 5);
        continue;
    }

    if ($http_code == 204) {
        $log->put('No members found for committee ' . $committee_number, 3);
        continue;
    }

    $data = json_decode($response, true);

    if ($data === null || !isset($data['Success'])) {
        $log->put('Error: Invalid JSON response for committee ' . $committee_number, 5);
        continue;
    }

    if ($data['Success'] !== true) {
        $log->put('Error: API reported failure for committee ' . $committee_number . ': ' . ($data['FailureMessage'] ?? 'Unknown error'), 5);
        continue;
    }

    if (empty($data['ListItems'])) {
        $log->put('No members found for committee ' . $committee_number, 3);
        continue;
    }

    // Process each member
    foreach ($data['ListItems'] as $member_data) {
        if (empty($member_data['MemberNumber'])) {
            $log->put('Warning: Member found without MemberNumber for committee ' . $committee_number, 4);
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

    $log->put('Found ' . count($data['ListItems']) . ' members for committee ' . $committee_number, 2);
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
