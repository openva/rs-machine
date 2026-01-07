<?php

###
# Retrieve and Store Minutes
#
# PURPOSE
# Retrieves the minutes from every meeting of the House and Senate and stores them.
#
###

# Verify we have the API key
if (!defined('LIS_KEY') || empty(LIS_KEY)) {
    $log->put('LIS_KEY is not configured—cannot retrieve minutes.', 6);
    return false;
}

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

# Retrieve minutes list from the API
$session_code = '20' . SESSION_LIS_ID;
$api_url = 'https://lis.virginia.gov/MinutesBook/api/getpublishedminutesbooklistasync?sessionCode=' . $session_code;

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
curl_close($ch);

if ($http_code != 200) {
    $log->put('Failed to retrieve minutes list from API: HTTP ' . $http_code, 6);
    return false;
}

$minutes_list = json_decode($response, true);

if (empty($minutes_list['Minutes'])) {
    $log->put('No minutes found in API response for session ' . $session_code, 3);
    return false;
}

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
    $detail_url = 'https://lis.virginia.gov/MinutesBook/api/getminutesbookasync?minutesBookID=' . $minutes_book_id;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $detail_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $detail_response = curl_exec($ch);
    $detail_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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
    $minutes_text = build_minutes_text($detail_data['MinutesBooks'][0]);

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
        }
    } else {
        $log->put('The retrieved minutes for ' . $minutes_date . ' in ' . $chamber . ' were '
            . ' suspiciously short, and not saved.', 6);
    }

    # Rate limit
    sleep(1);
}

/**
 * Build readable minutes text from the structured API response
 *
 * @param array $minutes_book The MinutesBook object from the API
 * @return string Formatted minutes text
 */
function build_minutes_text(array $minutes_book): string
{
    $text_parts = [];

    if (empty($minutes_book['MinutesCategories'])) {
        return '';
    }

    foreach ($minutes_book['MinutesCategories'] as $category) {
        # Add category description as a header if present
        if (!empty($category['CategoryDescription'])) {
            $text_parts[] = '<b>' . htmlspecialchars($category['CategoryDescription']) . '</b>';
        }

        if (empty($category['MinutesEntries'])) {
            continue;
        }

        foreach ($category['MinutesEntries'] as $entry) {
            $entry_text = '';

            # Add legislation number if present
            if (!empty($entry['LegislationNumber'])) {
                $entry_text .= '<b>' . htmlspecialchars($entry['LegislationNumber']) . '</b>';
                if (!empty($entry['LegislationDescription'])) {
                    $entry_text .= ' - ' . htmlspecialchars($entry['LegislationDescription']);
                }
            } elseif (!empty($entry['EntryText'])) {
                $entry_text .= htmlspecialchars($entry['EntryText']);
            }

            # Process activities
            if (!empty($entry['MinutesActivities'])) {
                foreach ($entry['MinutesActivities'] as $activity) {
                    # Skip deleted activities
                    if (!empty($activity['DeletionDate'])) {
                        continue;
                    }

                    $activity_text = '';

                    # Build text from activity references
                    if (!empty($activity['ActivityReferences'])) {
                        foreach ($activity['ActivityReferences'] as $ref) {
                            if (!empty($ref['ReferenceText'])) {
                                $activity_text .= htmlspecialchars($ref['ReferenceText']);
                            }
                        }
                    } elseif (!empty($activity['Description'])) {
                        $activity_text = htmlspecialchars($activity['Description']);
                    }

                    # Add vote tally if present
                    if (!empty($activity['VoteTally'])) {
                        $activity_text .= ' - ' . htmlspecialchars($activity['VoteTally']);
                    }

                    if (!empty($activity_text)) {
                        if (!empty($entry_text)) {
                            $entry_text .= '<br>' . "\n";
                        }
                        $entry_text .= $activity_text;
                    }
                }
            }

            if (!empty($entry_text)) {
                $text_parts[] = $entry_text;
            }
        }
    }

    return implode("\n\n", $text_parts);
}
