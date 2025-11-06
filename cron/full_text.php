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
                $log->put('Abandoning collecting bill text, after receiving ' . $server_errors
                    . ' consecutive error messages from the LIS server.', 4);
                return;
            }

            continue;
        }

        /*
         * For cases where LIS reports that no text exists, treat as a failed retrieval but do not
         * count it as a server error.
         */
        $server_errors = 0;

        $sql = 'UPDATE bills_full_text
				SET failed_retrievals = failed_retrievals+1
				WHERE id=' . $text['id'];
        mysqli_query($GLOBALS['db'], $sql);

        if ($status === 'no_text') {
            $log->put('Full text of ' . $text['number'] . ' was reported as lacking draft text: '
                . urlencode($url), 3);
        } else {
            if (mb_stripos($text['number'], 'CHAP') === false) {
                $log->put('Full text of ' . $text['number'] . ' came up blank (' . $message . '): '
                    . urlencode($url), 5);
            }
        }

        sleep(1);
        continue;
    }

    $full_text = trim((string)$fetch['text']);
    if ($full_text === '') {
        $server_errors = 0;
        $sql = 'UPDATE bills_full_text
				SET failed_retrievals = failed_retrievals+1
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
				SET failed_retrievals = failed_retrievals+1
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
    }

    $server_errors = 0;

    // Pause between requests to avoid hammering the server
    sleep(1);
}
