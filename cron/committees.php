<?php

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

/*
 * Helper function to generate a committee shortname from its name
 */
function generate_committee_shortname($name) {
    // Remove common prefixes/suffixes
    $name = preg_replace('/^(Committee on|Committee for|Subcommittee on|Subcommittee for|Committee|Subcommittee)\s+/i', '', $name);
    $name = preg_replace('/\s+(Committee|Subcommittee)$/i', '', $name);

    // Split into words and take first letter of each significant word
    $words = preg_split('/\s+/', trim($name));
    $shortname = '';

    foreach ($words as $word) {
        // Skip small words like 'and', 'of', 'the', 'for', 'on'
        if (strlen($word) > 2 && !in_array(strtolower($word), ['and', 'the', 'for'])) {
            $shortname .= strtolower($word[0]);
        }
    }

    // If we got nothing, just use the first word (lowercased and cleaned)
    if (empty($shortname) && !empty($words)) {
        $shortname = strtolower(preg_replace('/[^a-z]/i', '', $words[0]));
    }

    // Final fallback
    if (empty($shortname)) {
        $shortname = 'committee';
    }

    return $shortname;
}

/*
 * Fetch all committees (including subcommittees) from the Committee API
 */
$log->put('Fetching committee list from Committee API...', 3);

$api_committees = [];

// First fetch parent committees (those without a parent)
foreach (['H', 'S'] as $chamber_code) {
    $url = 'https://lis.virginia.gov/Committee/api/getcommitteelistasync';
    $query_params = [
        'sessionID' => SESSION_ID,
        'chamberCode' => $chamber_code
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

    if (!empty($data['Committees'])) {
        foreach ($data['Committees'] as $committee) {
            $api_committees[] = $committee;

            // For each parent committee, also fetch its subcommittees
            if (empty($committee['ParentCommitteeID']) && !empty($committee['CommitteeID'])) {
                $sub_url = 'https://lis.virginia.gov/Committee/api/getcommitteelistasync';
                $sub_query_params = [
                    'sessionID' => SESSION_ID,
                    'chamberCode' => $chamber_code,
                    'parentCommitteeID' => $committee['CommitteeID']
                ];

                $sub_ch = curl_init();
                curl_setopt($sub_ch, CURLOPT_URL, $sub_url . '?' . http_build_query($sub_query_params));
                curl_setopt($sub_ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($sub_ch, CURLOPT_HTTPHEADER, [
                    'WebAPIKey: ' . LIS_KEY,
                    'Accept: application/json'
                ]);

                $sub_response = curl_exec($sub_ch);
                $sub_http_code = curl_getinfo($sub_ch, CURLINFO_HTTP_CODE);

                if ($sub_response !== false && $sub_http_code == 200) {
                    $sub_data = json_decode($sub_response, true);
                    if (!empty($sub_data['Committees'])) {
                        foreach ($sub_data['Committees'] as $subcommittee) {
                            $api_committees[] = $subcommittee;
                        }
                    }
                }
            }
        }
    }
}

$log->put('Retrieved ' . count($api_committees) . ' total committees from API.', 3);

if (count($api_committees) < 20) {
    $log->put('Error: Too few committees retrieved from API (' . count($api_committees) . ').', 8);
    die('Error: Too few committees retrieved from API.');
}

/*
 * Separate parent committees from subcommittees
 */
$parent_committees = [];
$subcommittees_by_parent = [];

foreach ($api_committees as $committee) {
    if (empty($committee['ParentCommitteeID'])) {
        $parent_committees[] = $committee;
    } else {
        $parent_id = $committee['ParentCommitteeID'];
        if (!isset($subcommittees_by_parent[$parent_id])) {
            $subcommittees_by_parent[$parent_id] = [];
        }
        $subcommittees_by_parent[$parent_id][] = $committee;
    }
}

$log->put('Found ' . count($parent_committees) . ' parent committees and ' . (count($api_committees) - count($parent_committees)) . ' subcommittees.', 3);

/*
 * Track which committees we've seen from the API
 */
$seen_committee_keys = [];

/*
 * Process each parent committee and its subcommittees
 */
$updated_count = 0;
$inserted_count = 0;

foreach ($parent_committees as $parent_committee) {
    // Extract lis_id from CommitteeNumber (e.g., "H04" → 4, "S12" → 12)
    $committee_number = $parent_committee['CommitteeNumber'] ?? '';
    $lis_id = (int)preg_replace('/[^0-9]/', '', $committee_number);

    if ($lis_id === 0) {
        $log->put('Warning: Could not extract lis_id from CommitteeNumber: ' . $committee_number, 4);
        continue;
    }

    // Determine chamber
    $chamber_code = $parent_committee['ChamberCode'] ?? '';
    $chamber = (strtoupper($chamber_code) === 'H') ? 'house' : 'senate';

    // Mark this committee as seen
    $seen_committee_keys[] = ['lis_id' => $lis_id, 'chamber' => $chamber, 'parent_id' => null];

    // Check if this committee exists locally
    $check_sql = 'SELECT id, name, shortname, meeting_time, date_ended
                  FROM committees
                  WHERE lis_id = :lis_id
                    AND chamber = :chamber
                    AND parent_id IS NULL
                  LIMIT 1';
    $check_stmt = $GLOBALS['dbh']->prepare($check_sql);
    $check_stmt->execute([
        ':lis_id' => $lis_id,
        ':chamber' => $chamber
    ]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

    $name = $parent_committee['Name'] ?? '';
    $shortname = !empty($parent_committee['Abbreviation'])
        ? strtolower($parent_committee['Abbreviation'])
        : generate_committee_shortname($name);
    $meeting_time = $parent_committee['MeetingNote'] ?? null;

    if ($existing) {
        // Update existing committee
        $local_committee_id = $existing['id'];

        $update_sql = 'UPDATE committees
                       SET name = :name,
                           shortname = :shortname,
                           meeting_time = :meeting_time,
                           date_ended = NULL,
                           date_modified = NOW()
                       WHERE id = :id';
        $update_stmt = $GLOBALS['dbh']->prepare($update_sql);
        $update_stmt->execute([
            ':name' => $name,
            ':shortname' => $shortname,
            ':meeting_time' => $meeting_time,
            ':id' => $local_committee_id
        ]);

        $updated_count++;
        $log->put('Updated committee: ' . $name . ' (ID: ' . $local_committee_id . ')', 2);
    } else {
        // Insert new committee
        $insert_sql = 'INSERT INTO committees
                       SET lis_id = :lis_id,
                           parent_id = NULL,
                           name = :name,
                           shortname = :shortname,
                           chamber = :chamber,
                           meeting_time = :meeting_time,
                           date_started = :date_started,
                           date_created = NOW(),
                           date_modified = NOW()';
        $insert_stmt = $GLOBALS['dbh']->prepare($insert_sql);
        $insert_stmt->execute([
            ':lis_id' => $lis_id,
            ':name' => $name,
            ':shortname' => $shortname,
            ':chamber' => $chamber,
            ':meeting_time' => $meeting_time,
            ':date_started' => SESSION_START
        ]);

        $local_committee_id = $GLOBALS['dbh']->lastInsertId();
        $inserted_count++;
        $log->put('Inserted new committee: ' . $name . ' (ID: ' . $local_committee_id . ')', 3);
    }

    // Now process subcommittees for this parent
    $parent_api_id = $parent_committee['CommitteeID'];
    if (isset($subcommittees_by_parent[$parent_api_id])) {
        foreach ($subcommittees_by_parent[$parent_api_id] as $subcommittee) {
            // Extract lis_id from subcommittee CommitteeNumber
            $sub_committee_number = $subcommittee['CommitteeNumber'] ?? '';
            $sub_lis_id = (int)preg_replace('/[^0-9]/', '', $sub_committee_number);

            if ($sub_lis_id === 0) {
                $log->put('Warning: Could not extract lis_id from subcommittee CommitteeNumber: ' . $sub_committee_number, 4);
                continue;
            }

            // Subcommittees should have same chamber as parent
            $sub_chamber_code = $subcommittee['ChamberCode'] ?? '';
            $sub_chamber = (strtoupper($sub_chamber_code) === 'H') ? 'house' : 'senate';

            // Mark this subcommittee as seen
            $seen_committee_keys[] = ['lis_id' => $sub_lis_id, 'chamber' => $sub_chamber, 'parent_id' => $local_committee_id];

            // Check if this subcommittee exists locally
            $check_sub_sql = 'SELECT id, name, shortname, meeting_time, date_ended
                              FROM committees
                              WHERE lis_id = :lis_id
                                AND chamber = :chamber
                                AND parent_id = :parent_id
                              LIMIT 1';
            $check_sub_stmt = $GLOBALS['dbh']->prepare($check_sub_sql);
            $check_sub_stmt->execute([
                ':lis_id' => $sub_lis_id,
                ':chamber' => $sub_chamber,
                ':parent_id' => $local_committee_id
            ]);
            $existing_sub = $check_sub_stmt->fetch(PDO::FETCH_ASSOC);

            $sub_name = $subcommittee['Name'] ?? '';
            $sub_shortname = !empty($subcommittee['Abbreviation'])
                ? strtolower($subcommittee['Abbreviation'])
                : generate_committee_shortname($sub_name);
            $sub_meeting_time = $subcommittee['MeetingNote'] ?? null;

            if ($existing_sub) {
                // Update existing subcommittee
                $local_sub_id = $existing_sub['id'];

                $update_sub_sql = 'UPDATE committees
                                   SET name = :name,
                                       shortname = :shortname,
                                       meeting_time = :meeting_time,
                                       date_ended = NULL,
                                       date_modified = NOW()
                                   WHERE id = :id';
                $update_sub_stmt = $GLOBALS['dbh']->prepare($update_sub_sql);
                $update_sub_stmt->execute([
                    ':name' => $sub_name,
                    ':shortname' => $sub_shortname,
                    ':meeting_time' => $sub_meeting_time,
                    ':id' => $local_sub_id
                ]);

                $updated_count++;
                $log->put('Updated subcommittee: ' . $sub_name . ' (ID: ' . $local_sub_id . ')', 2);
            } else {
                // Insert new subcommittee
                $insert_sub_sql = 'INSERT INTO committees
                                   SET lis_id = :lis_id,
                                       parent_id = :parent_id,
                                       name = :name,
                                       shortname = :shortname,
                                       chamber = :chamber,
                                       meeting_time = :meeting_time,
                                       date_started = :date_started,
                                       date_created = NOW(),
                                       date_modified = NOW()';
                $insert_sub_stmt = $GLOBALS['dbh']->prepare($insert_sub_sql);
                $insert_sub_stmt->execute([
                    ':lis_id' => $sub_lis_id,
                    ':parent_id' => $local_committee_id,
                    ':name' => $sub_name,
                    ':shortname' => $sub_shortname,
                    ':chamber' => $sub_chamber,
                    ':meeting_time' => $sub_meeting_time,
                    ':date_started' => SESSION_START
                ]);

                $local_sub_id = $GLOBALS['dbh']->lastInsertId();
                $inserted_count++;
                $log->put('Inserted new subcommittee: ' . $sub_name . ' (ID: ' . $local_sub_id . ', Parent: ' . $local_committee_id . ')', 3);
            }
        }
    }
}

/*
 * Now find any local committees that weren't in the API response and mark them as ended
 */
$ended_count = 0;

$all_local_sql = 'SELECT id, lis_id, chamber, parent_id, name, date_ended
                  FROM committees
                  WHERE date_ended IS NULL';
$all_local_stmt = $GLOBALS['dbh']->prepare($all_local_sql);
$all_local_stmt->execute();
$all_local_committees = $all_local_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_local_committees as $local_committee) {
    // Check if this committee was seen in the API response
    $found = false;
    foreach ($seen_committee_keys as $seen_key) {
        if ($seen_key['lis_id'] == $local_committee['lis_id'] &&
            $seen_key['chamber'] == $local_committee['chamber'] &&
            $seen_key['parent_id'] == $local_committee['parent_id']) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        // This committee no longer exists in the API, mark it as ended
        $end_sql = 'UPDATE committees
                    SET date_ended = :date_ended,
                        date_modified = NOW()
                    WHERE id = :id';
        $end_stmt = $GLOBALS['dbh']->prepare($end_sql);
        $end_stmt->execute([
            ':date_ended' => SESSION_START,
            ':id' => $local_committee['id']
        ]);

        $ended_count++;
        $log->put('Marked committee as ended: ' . $local_committee['name'] . ' (ID: ' . $local_committee['id'] . ')', 3);
    }
}

$log->put('Committee sync complete: ' . $inserted_count . ' inserted, ' . $updated_count . ' updated, ' . $ended_count . ' ended.', 4);
