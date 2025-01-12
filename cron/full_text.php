<?php

###
# UPDATE BILLS' FULL TEXT
# What we're actually doing here is updating the bills_full_text table. Then we use that
# to synch up the data in the bills table later on. We don't bother with any bill's text that, after
# twenty tries, we just can't manage to retrieve.
###
/* This only works if there's already an entry in bills_full_text. */
$sql = 'SELECT bills_full_text.id, bills_full_text.number, sessions.lis_id AS session_id
		FROM bills_full_text
		LEFT JOIN bills
			ON bills_full_text.bill_id = bills.id
		LEFT JOIN sessions
			ON bills.session_id = sessions.id
		WHERE
			bills_full_text.text IS NULL
			AND bills_full_text.failed_retrievals < 20
			AND bills.session_id = ' . SESSION_ID . '
		ORDER BY
			bills_full_text.failed_retrievals ASC,
			sessions.year DESC,
			bills.date_introduced DESC,
			bills_full_text.date_introduced DESC';

$result = mysqli_query($GLOBALS['db'], $sql);

if (mysqli_num_rows($result) == 0) {
    $log->put('Found no bills lacking their full text.', 1);
    return false;
}

/*
 * We don't want to keep hammering on the server if it is returning errors.
 */
$server_errors = 0;

while ($text = mysqli_fetch_array($result)) {
    # Retrieve the full text.
    $url = 'https://lis.virginia.gov/LegislationText/api/GetLegislationTextByIDAsync?sessionCode=20'
        . $text['session_id'] . '&documentNumber=' . $text['number'];
    $ch = curl_init($url);
    $headers = [ 'WebAPIKey: ' . LIS_KEY ];
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);

    /*
     * Check that the cURL request was successful (HTTP status code 2XX).
     */
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 200 && $http_code < 300) {
        $full_text = $response;
    } else {
        echo $http_code . ': ' . $url . "\n";
        $server_errors++;
        continue;
    }

    /*
     * If too many consecutive server errors have been returned, give up, and stop hammering on
     * LIS's server.
     */
    if ($server_errors >= 20) {
        $log->put('Abandoning collecting bill text, after receiving ' . $server_errors
            . ' consecutive error messages from the LIS server.');
        return;
    }

    curl_close($ch);

    // Extract the full text from the API's JSON response
    $response = json_decode($response);
    $full_text = $response->TextsList[0]->DraftText;

    if (
        $full_text == 'There is no draft text for the provided document code and session code'
        || $full_text == 'There is no html file for the provided document code and session code'
    ) {
        unset($full_text);
        $log->put('Full text of ' . $text['number'] . ' was reported as lacking draft text: ' . urlencode($url), 3);
    } else {
        // Convert into an array.
        $full_text = str_replace('</p> <p', "</p>\n<p", $full_text);
        $full_text = explode("\n", $full_text);

        # Clean up the bill's full_text.
        $full_text_clean = '';
        for ($i = 0; $i < count($full_text); $i++) {
            // Determine where the actual bill text begins.
            if (
                stristr($full_text[$i], 'Be it enacted by')
                ||
                stristr($full_text[$i], 'WHEREAS ')
                ||
                stristr($full_text[$i], 'RESOLVED by the ')
            ) {
                $law_start = true;
            }

            // Replace the legislature's style tags with semantically meaningful tags
            if (isset($law_start)) {
                $full_text[$i] = str_replace('<em class=new>', '<ins>', $full_text[$i]);
                $full_text[$i] = str_replace('</em>', '</ins>', $full_text[$i]);

                // Append this line to our cleaned-up, stripped-down text.
                $full_text_clean .= $full_text[$i] . "\n";
            }
        }
        unset($full_text);
        unset($start);
        unset($law_start);

        // Strip out unacceptable tags
        $full_text = trim(strip_tags($full_text_clean, '<p><b><i><em><strong><u><a><br><center><s><strike><ins>'));
    }

    if (!empty($full_text)) {
        # Clean up the text for inserting into the database
        $full_text = trim($full_text);
        $full_text = mysqli_real_escape_string($GLOBALS['db'], $full_text);

        if (!empty($full_text)) {
            # We store the bill's text, and also reset the counter that tracks failed attempts
            # to retrieve the text from the legislature's website.
            $sql = 'UPDATE bills_full_text
					SET text="' . $full_text . '", failed_retrievals=0
					WHERE id=' . $text['id'];
            $result2 = mysqli_query($GLOBALS['db'], $sql);
            if (!$result2) {
                $log->put('Error: Inserting ' . strtoupper($text['number']) . ' bill text failed.', 5);
            } else {
                $log->put('Inserting ' . strtoupper($text['number']) . ' bill text succeeded.', 2);
            }
        }

        /*
         * Reset server errors
         */
        $server_errors = 0;

        # Unset the variables that we used here.
        unset($start);
        unset($full_text);
        unset($full_text_clean);
    } else {
        # Increment the failed retrievals counter.
        $sql = 'UPDATE bills_full_text
				SET failed_retrievals = failed_retrievals+1
				WHERE id=' . $text['id'];
        mysqli_query($GLOBALS['db'], $sql);

        # Ignore bills that have been codified into law -- we don't need to be
        # told about those.
        if (mb_stripos($text['number'], 'CHAP') === false) {
            $log->put('Full text of ' . $text['number'] . ' came up blank: ' . urlencode($url), 5);
        }
    }

    // Pause between requests to avoid hammering the server
    sleep(1);
}
