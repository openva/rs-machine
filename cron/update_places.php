<?php

/*
 * Tag bills with the counties, cities, and towns that they name, for the places pages and the maps.
 *
 * Bills from 2024 on are checked once each, and again whenever their text changes (full_text.php
 * clears bills.places_checked). Each bill is marked as checked even if it names no places, so that
 * it isn't checked over and over. Bills that name counties and cities plainly are tagged without
 * asking GPT; only the rest are sent to it, ten a run. See RsMachine\PlaceTagger.
 */

use RsMachine\PlaceNames;
use RsMachine\PlaceTagger;

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific
# page.
$dbh = new Database();
$db = $dbh->connect();

$log = new Log();

/*
 * How much to do in a run. Settling a bill without GPT costs only reading it from the database, so
 * many are done each run. Asking GPT costs money, so only a few are.
 */
const PLACES_BILLS_PER_RUN = 1000;
const PLACES_MODEL_CALLS_PER_RUN = 10;
const PLACES_SECONDS_PER_RUN = 240;

/*
 * Ask GPT for the names of the places in a text. The connection to its endpoint is made when it's
 * first needed, and reused after that.
 */
$curl = null;
$role = 'You are a helpful assistant who identifies the names of places mentioned in '
    . 'the languages of legislation before the Virginia General Assembly. Given the text '
    . 'of a bill, you will extract the name of every Virginia county, city, and town mentioned, '
    . 'creating a list of them. You will separate each place name by commas. You will use the full name, of each place '
    . '(e.g. "City of Fairfax," "County of Fairfax," or "Town of Scottsville.") If no places '
    . 'are in the text at all, remain silent.' . "\n\n";

/*
 * While OpenAI's spend limit is reached, bills are still tagged by exact matching, and those that
 * need GPT wait (see RsMachine\OpenAiQuota).
 */
$quota = \RsMachine\OpenAiQuota::shared();
$openai_paused = $quota->pausedUntil() !== null;

$model_available = function () use (&$openai_paused): bool {
    return defined('OPENAI_KEY') && OPENAI_KEY !== '' && !$openai_paused;
};

$ask_model = function (string $text) use (&$curl, &$openai_paused, $model_available, $quota, $role, $log): ?array {
    if (!$model_available()) {
        return null;
    }
    if ($curl === null) {
        $curl = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_KEY
        ]);
    }

    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $role],
            ['role' => 'user', 'content' => 'Please extract place names from the following text: ' . $text]
        ]
    ]));
    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        $log->put('ERROR: Could not query OpenAI API, with this failure: ' . curl_error($curl), 3);
        return null;
    }

    $response = json_decode((string) $response, true);
    if (\RsMachine\OpenAiQuota::isExhausted($response)) {
        $quota->recordExhausted($log, 'update_places', $response);
        $openai_paused = true;
        return null;
    }
    if (!isset($response['choices'][0]['message']['content'])) {
        $reason = is_array($response) ? trim((string) ($response['error']['message'] ?? '')) : '';
        $log->put('OpenAI did not identify the places in a bill'
            . ($reason !== '' ? ': “' . $reason . '”' : ', and gave no reason') . '.', 3);
        return null;
    }
    $quota->clear();
    return PlaceNames::fromAnswer($response['choices'][0]['message']['content']);
};

/*
 * Connect to Memcached, as we may well be interacting with it during this session.
 */
$mc = null;
if (MEMCACHED_SERVER != '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

// Bills' text is read from the database (a null here means the default).
$tagger = new PlaceTagger($db, null, $ask_model, $model_available);
$stats = $tagger->run(
    PLACES_BILLS_PER_RUN,
    PLACES_MODEL_CALLS_PER_RUN,
    time() + PLACES_SECONDS_PER_RUN
);

/*
 * Clear the bills from Memcached.
 */
if ($mc instanceof Memcached) {
    foreach ($stats['checked_ids'] as $bill_id) {
        $mc->delete('bill-' . $bill_id);
    }
}

if ($stats['checked'] > 0 || $stats['unretrieved'] > 0) {
    $log->put(
        'Checked ' . $stats['checked'] . ' bills for place names (' . $stats['exact'] . ' without GPT, '
            . $stats['model'] . ' with it), tagging ' . $stats['tagged'] . ' places. '
            . $stats['unretrieved'] . ' could not be checked, and ' . $stats['waiting']
            . ' are waiting for GPT.',
        $stats['unretrieved'] > 0 ? 4 : 2
    );
}
