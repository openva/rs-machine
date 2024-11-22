<?php

###
# UPDATE BILLS' FULL TEXT
# What we're actually doing here is updating the bills_full_text table. Then we use that
# to synch up the data in the bills table later on. We don't bother with any bill's text that, after
# twenty tries, we just can't manage to retrieve.
###
/* This only works if there's already an entry in bills_full_text. */
$sql = 'SELECT bills_full_text.id, bills_full_text.number, sessions.lis_id
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

# Fire up HTML Purifier.
//$config = HTMLPurifier_Config::createDefault();
//$purifier = new HTMLPurifier($config);

/*
 * We don't want to keep hammering on the server if it is returning errors.
 */
$server_errors = 0;

while ($text = mysqli_fetch_array($result)) {
    # Retrieve the full text.
    $url = 'https://legacylis.virginia.gov/cgi-bin/legp604.exe?' . $text['lis_id'] . '+ful+'
        . strtoupper($text['number']);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    $response = curl_exec($ch);

    /*
     * Check that the cURL request was successful (HTTP status code 2XX).
     */
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 200 && $http_code < 300) {
        $full_text = $response;
    } else {
        $server_errors++;
        continue;
    }

    /*
     * Check for the legislature’s error that indicates excessive traffic. They send an HTTP 200,
     * unfortunately, so we have to determine the error state based on the content.
     */
    if (stristr($full_text, 'your query could not be processed') !== false) {
        $server_errors++;
        sleep(10);
        continue;
    }

    /*
     * If too many consecutive server errors have been returned, give up, and stop hammering on
     * LIS's server.
     */
    if ($server_errors >= 20) {
        $log->put('Abandoning collecting bill text, after receiving ' . $server_errors
            . ' consecutive rate-limiting error messages from the LIS server.');
        return;
    }

    curl_close($ch);

    # Convert the legislature's Windows-1252 text to UTF-8.
    $full_text = iconv('windows-1252', 'UTF-8', $full_text);

    # Convert into an array.
    $full_text = explode("\n", $full_text);

    # Clean up the bill's full_text.
    $full_text_clean = '';
    for ($i = 0; $i < count($full_text); $i++) {
        if (!isset($start)) {
            if (stristr($full_text[$i], 'HOUSE BILL NO. ')) {
                $start = true;
            } elseif (stristr($full_text[$i], 'SENATE BILL NO. ')) {
                $start = true;
            } elseif (stristr($full_text[$i], 'SENATE JOINT RESOLUTION NO. ')) {
                $start = true;
            } elseif (stristr($full_text[$i], 'HOUSE JOINT RESOLUTION NO. ')) {
                $start = true;
            } elseif (stristr($full_text[$i], 'SENATE RESOLUTION NO. ')) {
                $start = true;
            } elseif (stristr($full_text[$i], 'HOUSE RESOLUTION NO. ')) {
                $start = true;
            } elseif (stristr($full_text[$i], 'VIRGINIA ACTS OF ASSEMBLY')) {
                $start = true;
            }
        }

        # Finally, we're at the text of the bill.
        if (isset($start)) {
            # This is the end of the text.
            if (stristr($full_text[$i], '<div id="ftr"></div>')) {
                break;
            }

            # Otherwise, add this line to our bill text.
            else {
                # Determine where the header text ends and the actual law begins.
                if (stristr($full_text[$i], 'Be it enacted by')) {
                    $law_start = true;
                }

                if (isset($law_start) && ($law_start == true)) {
                    $full_text[$i] = str_replace('<i>', '<ins>', $full_text[$i]);
                    $full_text[$i] = str_replace('</i>', '</ins>', $full_text[$i]);
                }

                # Finally, append this line to our cleaned-up, stripped-down text.
                $full_text_clean .= $full_text[$i] . ' ';
            }
        }
    }
    unset($full_text);
    unset($start);
    unset($law_start);

    # Strip out unacceptable tags and prefix the description with its two prefix
    # tags.  Then provide a domain name for all links.
    $full_text = trim(strip_tags($full_text_clean, '<p><b><i><em><strong><u><a><br><center><s><strike><ins>'));

    if (!empty($full_text)) {
        # Replace relative links with absolute ones.
        $full_text = str_ireplace('href="/', 'href="http://lis.virginia.gov/', $full_text);

        # Replace links to the state code with links to Virginia Decoded.
        //$full_text = str_ireplace('href="http://law.lis.virginia.gov/vacode', 'href="https://vacode.org', $full_text);

        # Any time that we've just got a question mark hanging out, that should be a section
        # symbol.
        $full_text = str_replace(' ? ', ' §&nbsp;', $full_text);

        # Put the data back into the database, but clean it up first.
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
                $log->put('Insertion of  ' . strtoupper($text['number']) . ' bill text failed.', 5);
            } else {
                $log->put('Insertion of  ' . strtoupper($text['number']) . ' bill text succeeded.', 2);
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

        sleep(3);
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
}
