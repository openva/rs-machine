<?php

###
# Retrieve and Store Minutes
#
# PURPOSE
# Retrieves the minutes from every meeting of the House and Senate and stores them.
#
###

# Verify we have the API key for Senate minutes (House minutes do not use LIS)
$senate_enabled = true;
if (!defined('LIS_KEY') || empty(LIS_KEY)) {
    $log->put('LIS_KEY is not configured—cannot retrieve Senate minutes.', 6);
    $senate_enabled = false;
}

require_once(__DIR__ . '/../includes/class.Import.php');
$import = new Import($log);

# Get existing minutes from database to avoid duplicates
$sql = 'SELECT date, chamber
        FROM minutes';
$result = mysqli_query($GLOBALS['db'], $sql);
$past_minutes = [];
if (mysqli_num_rows($result) > 0) {
    while ($tmp = mysqli_fetch_array($result)) {
        $past_minutes[] = $tmp;
    }
}
$today = date('Y-m-d');

if ($senate_enabled) {
    # Retrieve minutes list from the API
    $session_code = '20' . SESSION_LIS_ID;
    $api_url = 'https://lis.virginia.gov/MinutesBook/api/getpublishedminutesbooklistasync?sessionCode='
        . $session_code;

    $headers = [
        'Content-Type: application/json',
        'WebAPIKey: ' . LIS_KEY
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code != 200) {
        $log->put('Failed to retrieve minutes list from API: HTTP ' . $http_code, 3);
    } else {
        $minutes_list = json_decode($response, true);

        if (empty($minutes_list['Minutes'])) {
            $log->put('No minutes found in API response for session ' . $session_code, 3);
        } else {
            # Process each minutes entry
            foreach ($minutes_list['Minutes'] as $minutes_book) {
                # Skip committee minutes (only process floor minutes)
                if (!empty($minutes_book['CommitteeID'])) {
                    continue;
                }

                # Extract the date and chamber
                $minutes_date = date('Y-m-d', strtotime($minutes_book['MinutesDate']));
                $chamber = ($minutes_book['ChamberCode'] === 'H') ? 'house' : 'senate';
                $minutes_book_id = $minutes_book['MinutesBookID'];

                # Check if we already have these minutes
                $is_duplicate = false;
                foreach ($past_minutes as $past) {
                    if ($past['chamber'] === $chamber && $past['date'] === $minutes_date) {
                        $is_duplicate = true;
                        break;
                    }
                }

                if ($is_duplicate) {
                    continue;
                }

                # Fetch the full minutes content
                $detail_url = 'https://lis.virginia.gov/MinutesBook/api/getminutesbookasync?minutesBookID='
                    . $minutes_book_id;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $detail_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $detail_response = curl_exec($ch);
                $detail_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($detail_http_code != 200) {
                    $log->put('Failed to retrieve minutes detail for ' . $minutes_date . ' ' . $chamber
                        . ': HTTP ' . $detail_http_code, 5);
                    continue;
                }

                $detail_data = json_decode($detail_response, true);

                if (empty($detail_data['MinutesBooks'][0]['MinutesCategories'])) {
                    $log->put('No minutes content for ' . $minutes_date . ' ' . $chamber, 4);
                    continue;
                }

                # Build the minutes text from the structured data
                $minutes_text = $import->build_minutes_text($detail_data['MinutesBooks'][0]);

                # If, after all that, we still have any text in these minutes
                if (strlen($minutes_text) > 150) {
                    # Prepare for MySQL
                    $minutes_text = mysqli_real_escape_string($GLOBALS['db'], $minutes_text);

                    # Insert the minutes into the database
                    $sql = 'INSERT INTO minutes
                            SET date = "' . $minutes_date . '", chamber="' . $chamber . '",
                            text="' . $minutes_text . '"';
                    $result = mysqli_query($GLOBALS['db'], $sql);

                    if (!$result) {
                        $log->put('Inserting the minutes for ' . $minutes_date . ' in ' . $chamber
                            . ' failed. ' . mysqli_error($GLOBALS['db']), 7);
                    } else {
                        $log->put('Inserted the minutes for ' . $minutes_date . ' in ' . $chamber . '.', 2);
                        $past_minutes[] = ['date' => $minutes_date, 'chamber' => $chamber];
                    }
                } else {
                    $log->put('The retrieved minutes for ' . $minutes_date . ' in ' . $chamber . ' were '
                        . ' suspiciously short, and not saved.', 6);
                }

                # Rate limit
                sleep(1);
            }
        }
    }
}

# Retrieve and store House floor minutes from the Heroku scraper
$house_minutes_base_url = 'https://hod-minutes.herokuapp.com/vga_day/';
$house_minutes_start_id = 1956;
$house_state_file = __DIR__ . '/house_minutes_state.json';
$house_state = [];

if (file_exists($house_state_file)) {
    $raw_state = file_get_contents($house_state_file);
    $decoded_state = json_decode($raw_state, true);
    if (is_array($decoded_state)) {
        $house_state = $decoded_state;
    } else {
        $log->put('House minutes state file is invalid; starting from default.', 4);
    }
}

$last_house_id = isset($house_state['last_id']) ? (int)$house_state['last_id'] : 0;
$next_house_id = max($house_minutes_start_id, $last_house_id + 1);
$max_house_id_seen = $last_house_id;
$house_max_iterations = 500;
$consecutive_failures = 0;
$max_consecutive_failures = 10;

for ($offset = 0; $offset < $house_max_iterations; $offset++) {
    $requested_id = $next_house_id + $offset;
    $house_url = $house_minutes_base_url . $requested_id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $house_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $house_response = curl_exec($ch);
    $house_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($house_http_code != 200 || $house_response === false) {
        $log->put('Failed to retrieve House minutes at ' . $house_url
            . ': HTTP ' . $house_http_code, 4);
        $consecutive_failures++;
        if ($consecutive_failures >= $max_consecutive_failures) {
            $log->put('Stopping iterating through House minutes after ' . $consecutive_failures
                . ' consecutive failures', 4);
            break;
        }
        continue;
    }

    $actual_house_id = $import->parse_house_minutes_id($house_response);
    if ($actual_house_id === null) {
        $log->put('Could not locate House minutes ID at ' . $house_url, 5);
        $consecutive_failures++;
        if ($consecutive_failures >= $max_consecutive_failures) {
            $log->put('Stopping iterating through House minutes after ' . $consecutive_failures
                . ' consecutive failures', 4);
            break;
        }
        continue;
    }

    // Reset consecutive failures on success
    $consecutive_failures = 0;

    if ($actual_house_id < $requested_id) {
        # We have stepped past the most recent minutes.
        break;
    }

    $house_minutes = $import->extract_house_minutes_data($house_response);
    if (empty($house_minutes) || empty($house_minutes['date']) || empty($house_minutes['text'])) {
        $log->put('Could not parse House minutes content at ' . $house_url, 5);
        $consecutive_failures++;
        if ($consecutive_failures >= $max_consecutive_failures) {
            $log->put('Stopping after ' . $consecutive_failures . ' consecutive failures', 4);
            break;
        }
        continue;
    }

    // Successfully parsed minutes, reset failure counter
    $consecutive_failures = 0;

    $minutes_date = $house_minutes['date'];
    $minutes_text = $house_minutes['text'];
    $chamber = 'house';

    if ($minutes_date > $today) {
        $log->put('Skipping future House minutes dated ' . $minutes_date . '.', 3);
        $max_house_id_seen = max($max_house_id_seen, $actual_house_id);
        continue;
    }

    # Check if we already have these minutes
    $is_duplicate = false;
    foreach ($past_minutes as $past) {
        if ($past['chamber'] === $chamber && $past['date'] === $minutes_date) {
            $is_duplicate = true;
            break;
        }
    }

    if ($is_duplicate) {
        $max_house_id_seen = max($max_house_id_seen, $actual_house_id);
        continue;
    }

    $word_count = count_minutes_words($minutes_text);
    if ($word_count >= 120) {
        $minutes_text = mysqli_real_escape_string($GLOBALS['db'], $minutes_text);
        $sql = 'INSERT INTO minutes
                SET date = "' . $minutes_date . '", chamber="' . $chamber . '",
                text="' . $minutes_text . '"';
        $result = mysqli_query($GLOBALS['db'], $sql);

        if (!$result) {
            $log->put('Inserting the House minutes for ' . $minutes_date . ' failed. '
                . mysqli_error($GLOBALS['db']), 7);
        } else {
            $log->put('Inserted the House minutes for ' . $minutes_date . '.', 2);
            $past_minutes[] = ['date' => $minutes_date, 'chamber' => $chamber];
        }
    } else {
        $log->put('The retrieved House minutes for ' . $minutes_date . ' had only '
            . $word_count . ' words, and were not saved.', 6);
    }

    $max_house_id_seen = max($max_house_id_seen, $actual_house_id);

    # Rate limit
    sleep(1);
}

if ($max_house_id_seen > $last_house_id) {
    $house_state['last_id'] = $max_house_id_seen;
    $house_state['updated_at'] = date('c');
    file_put_contents($house_state_file, json_encode($house_state));
}

/**
 * Count words in minutes text, ignoring HTML markup.
 *
 * @param string $text
 * @return int
 */
function count_minutes_words(string $text): int
{
    $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($plain === '') {
        return 0;
    }

    preg_match_all('/[\\p{L}\\p{N}]+/u', $plain, $matches);
    return isset($matches[0]) ? count($matches[0]) : 0;
}
