<?php

###
# UPDATE BILLS' FULL TEXT
# What we're actually doing here is updating the bills_full_text table. Then we use that
# to synch up the data in the bills table later on.
#
# A version's text is often published after the version is listed, sometimes weeks after (e.g.,
# a chapter's). So a version is tried on every run 20 times, about six hours' worth, then once a
# day (each failure updates date_modified), until failed_retrievals reaches 255, its maximum:
# about eight months.
###
/* This only works if there's already an entry in bills_full_text. */
$sql = 'SELECT
            bills_full_text.id,
            bills_full_text.bill_id,
            bills_full_text.number,
            bills_full_text.failed_retrievals,
            sessions.lis_id AS session_id,
            bills.date_introduced
		FROM bills_full_text
		LEFT JOIN bills
			ON bills_full_text.bill_id = bills.id
		LEFT JOIN sessions
			ON bills.session_id = sessions.id
		WHERE
			bills_full_text.text IS NULL
			AND bills_full_text.failed_retrievals < 255
			AND (
				bills_full_text.failed_retrievals < 20
				OR bills_full_text.date_modified < NOW() - INTERVAL 1 DAY
			)
			AND bills.session_id = ' . SESSION_ID . '
		ORDER BY
			bills_full_text.failed_retrievals ASC,
			sessions.year DESC,
			bills.date_introduced DESC,
			bills_full_text.date_introduced DESC';

$result = mysqli_query($GLOBALS['db'], $sql);

if (mysqli_num_rows($result) == 0) {
    $log->put('Found no bills lacking their full text.', 1);
    return \RsMachine\TaskResult::skipped('no bills lack their full text');
}

/*
 * We don't want to keep hammering on the server if it is returning errors.
 */
$server_errors = 0;

if (!isset($log) || !($log instanceof Log)) {
    $log = new Log();
}
$import = new Import($log);

while ($text = mysqli_fetch_array($result)) {
    $fetch = $import->fetch_bill_text_from_api($text['number'], $text['session_id']);

    if ($fetch['success'] !== true) {
        $status = $fetch['status'] ?? 'error';
        $message = $fetch['message'] ?? 'Unknown error';
        $url = $fetch['url'] ?? '';

        if (in_array($status, ['transport_error', 'json_error', 'validation_error'], true)) {
            $server_errors++;
            $log->put('Error retrieving bill text for ' . $text['number'] . ': ' . $message, 5);

            if ($server_errors >= 20) {
                return \RsMachine\TaskResult::failed('abandoned collecting bill text, after '
                    . $server_errors . ' consecutive errors from the LIS API');
            }

            continue;
        }

        /*
         * For cases where LIS reports that no text exists, treat as a failed retrieval but do not
         * count it as a server error.
         */
        $server_errors = 0;

        $sql = 'UPDATE bills_full_text
				SET failed_retrievals = LEAST(failed_retrievals + 1, 255)
				WHERE id=' . $text['id'];
        mysqli_query($GLOBALS['db'], query: $sql);

        // Said once, when the version is to be tried daily instead (see the top of this file).
        // Some versions never have text here: budget bills' (HB30ER) are on LIS's budget site.
        if ($status === 'no_text' && (int) $text['failed_retrievals'] === 19) {
            $log->put('Full text of ' . $text['number'] . ' was reported as not having any text '
                . 'yet, after 20 tries. It will be tried once a day from now on.', 3);
        }

        sleep(1);
        continue;
    }

    $full_text = trim((string)$fetch['text']);
    if ($full_text === '') {
        $server_errors = 0;
        $sql = 'UPDATE bills_full_text
				SET failed_retrievals = LEAST(failed_retrievals + 1, 255)
				WHERE id=' . $text['id'];
        mysqli_query($GLOBALS['db'], $sql);
        if (mb_stripos($text['number'], 'CHAP') === false) {
            $log->put('Full text of ' . $text['number'] . ' came up blank after sanitisation: '
                . urlencode((string)($fetch['url'] ?? '')), 5);
        }
        sleep(1);
        continue;
    }

    $escaped_text = mysqli_real_escape_string($GLOBALS['db'], $full_text);
    if ($escaped_text === '') {
        $server_errors = 0;
        $sql = 'UPDATE bills_full_text
				SET failed_retrievals = LEAST(failed_retrievals + 1, 255)
				WHERE id=' . $text['id'];
        mysqli_query($GLOBALS['db'], $sql);
        if (mb_stripos($text['number'], 'CHAP') === false) {
            $log->put('Full text of ' . $text['number'] . ' could not be stored (empty after escaping).', 5);
        }
        sleep(1);
        continue;
    }

    $sql = 'UPDATE bills_full_text
			SET text="' . $escaped_text . '", failed_retrievals=0
			WHERE id=' . $text['id'];
    $result2 = mysqli_query($GLOBALS['db'], $sql);
    if (!$result2) {
        $log->put('Error: Inserting ' . strtoupper($text['number']) . ' bill text failed.', 5);
    } else {
        $log->put('Inserting ' . strtoupper($text['number']) . ' bill text succeeded.', 2);

        // The bill's text has changed, so it needs to be checked for place names again (see
        // update_places.php). The bill's date_modified is left alone, since the bill hasn't changed.
        mysqli_query(
            $GLOBALS['db'],
            'UPDATE bills SET places_checked = NULL, date_modified = date_modified WHERE id = '
                . (int) $text['bill_id']
        );
    }

    $server_errors = 0;

    // Pause between requests to avoid hammering the server
    sleep(1);
}
