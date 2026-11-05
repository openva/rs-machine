<?php

###
# UPDATE BILL SUMMARIES
#
# Summaries.csv has a row for each of a bill's summaries (as introduced, as passed, etc.). Each
# bill gets its latest; see RsMachine\Summaries.
###

use RsMachine\Summaries;

/*
 * Connect to Memcached, since we'll be interacting with it during this session.
 */
$mc = null;
if (MEMCACHED_SERVER != '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

/*
 * Don't bother if the file hasn't changed.
 */
$summaries_hash = md5(file_get_contents(__DIR__ . '/summaries.csv'));
if ($mc instanceof Memcached && $mc->get('summaries-csv-hash') == $summaries_hash) {
    return \RsMachine\TaskResult::skipped('summaries.csv is unchanged');
}

/*
 * Open the file, and read every row but the header.
 */
$fp = fopen(__DIR__ . '/summaries.csv', 'r');
if ($fp === false) {
    $log->put('summaries.csv could not be read from the filesystem.', 8);
    return false;
}
$rows = [];
$first = true;
while (($row = fgetcsv($fp, 0, ',')) !== false) {
    if ($first) {
        $first = false;
        continue;
    }
    // A row with too few fields is a CSV parsing error.
    if (count($row) < 4) {
        continue;
    }
    $rows[] = ['number' => $row[0], 'doc_id' => trim($row[1]), 'type' => $row[2], 'text' => $row[3]];
}
fclose($fp);

$summaries = Summaries::latest($rows);

/*
 * Also, retrieve our saved serialized array of hash data, so that we can only update summaries
 * that have changed, or that are new.
 */
$hash_path = __DIR__ . '/hashes/summaries-' . SESSION_ID . '.md5';
$hashes = [];
if (file_exists($hash_path)) {
    $hashes = unserialize((string) file_get_contents($hash_path));
    if (!is_array($hashes)) {
        $hashes = [];
    }
} elseif (!file_exists(__DIR__ . '/hashes/')) {
    mkdir(__DIR__ . '/hashes');
}

/*
 * Generate a list of all bills and their numbers, to use to make comparisons.
 */
$bills = [];
$sql = 'SELECT bills.id, bills.number
		FROM bills
		WHERE session_id = ' . $session_id;
$result = mysqli_query($GLOBALS['db'], $sql);
while ($bill = mysqli_fetch_array($result)) {
    $bills[$bill['number']] = $bill['id'];
}

/*
 * This script often hits the default lock wait timeout of 50 seconds, resulting in the summary not
 * being updated. We double it to 100, since there's no hurry here.
 */
$sql = 'SET innodb_lock_wait_timeout=100';
mysqli_query($GLOBALS['db'], $sql);

/*
 * Whether every summary was stored. If not, the file mustn't be marked as processed, or the
 * summaries that weren't stored would be skipped until LIS changes the file.
 */
$all_stored = true;
$updated = 0;

foreach ($summaries as $number => $summary) {
    /*
     * See if this bill's summary is new or different than last time that we stored it.
     */
    $hash = md5(serialize($summary));
    if (isset($hashes[$number]) && ($hash == $hashes[$number])) {
        continue;
    }

    /*
     * Remove the paragraph tags, newlines, NBSPs and double spaces.
     */
    $summary['text'] = str_replace("\r", ' ', $summary['text']);
    $summary['text'] = str_replace("\n", ' ', $summary['text']);
    $summary['text'] = str_replace('&nbsp;', ' ', $summary['text']);
    $summary['text'] = str_replace('  ', ' ', $summary['text']);
    $summary['text'] = str_replace(' ', ' ', $summary['text']);

    # There is often an HTML mistake in this tag, so we perform this replacement after
    # running HTML Purifier, not before.
    $summary['text'] = str_replace('<br clear="all" /> ', ' ', $summary['text']);
    $summary['text'] = strip_tags($summary['text'], '<b><i><em><strong>');

    # Clean up the bolding, so that we don't bold a blank space.
    $summary['text'] = str_replace(' </b>', '</b> ', $summary['text']);

    # Trim off any whitespace.
    $summary['text'] = trim($summary['text']);

    # Hack off a hanging non-breaking space, if there is one.
    if (substr($summary['text'], -7) == ' &nbsp;') {
        $summary['text'] = substr($summary['text'], 0, -8);
    }

    if (empty($summary['text'])) {
        $log->put('Summary of ' . strtoupper($number) . ' is blank.', 2);
        $hashes[$number] = $hash;
        continue;
    }

    /*
     * Look up the bill ID for this bill number. A bill that isn't in the database yet gets its
     * summary once it is.
     */
    $bill_id = $bills[$number] ?? null;
    if (empty($bill_id)) {
        $log->put('Summary found for ' . strtoupper($number) . ', but we have no record of that bill.', 2);
        $all_stored = false;
        continue;
    }

    /*
     * Commit this to the database.
     */
    $sql = 'UPDATE bills
			SET summary="' . mysqli_real_escape_string($GLOBALS['db'], $summary['text']) . '"
			WHERE id=' . $bill_id;
    try {
        $result = mysqli_query($GLOBALS['db'], $sql);
        $error = $result ? '' : mysqli_error($GLOBALS['db']);
    } catch (mysqli_sql_exception $e) {
        $result = false;
        $error = $e->getMessage();
    }
    if (!$result) {
        $log->put('Insertion of ' . strtoupper($number) . ' summary failed, and will be retried. '
            . 'Error: ' . $error, 6);
        $all_stored = false;
        continue;
    }

    $hashes[$number] = $hash;
    $updated++;
    $log->put('Stored the ' . strtolower($summary['type']) . ' of ' . strtoupper($number) . '.', 1);
    if ($mc instanceof Memcached) {
        $mc->delete('bill-' . $bill_id);
    }
}

# Store our per-bill hashes array to a file, so that we can open it up next time and see which
# bills have changed.
file_put_contents($hash_path, serialize($hashes));

if ($all_stored && $mc instanceof Memcached) {
    $mc->set('summaries-csv-hash', $summaries_hash);
}

$log->put('Stored ' . $updated . ' bill summaries.', 2);
