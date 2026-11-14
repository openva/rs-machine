<?php

/*
 * Write recent days' briefs: a few sentences about what the General Assembly did on a day, written
 * by a language model from the day's records alone, and checked against them (see
 * richmondsunlight.com's htdocs/includes/class.DayBrief.php, which is in includes/, and its
 * docs/minutes-replacement.md). The day pages and the RSS feed of days show them, through the API.
 *
 * A day's brief is written the morning after (in time for the feed, at 7 a.m.), and written again,
 * for three days, only if what the model is told about the day changes: its highlights, their
 * reasons, or the news about them. A brief that fails its checks is stored as rejected (so that
 * it isn't asked for again until the day changes), and the day's page shows its summary instead.
 */

if (!class_exists('DayBrief')) {
    $log->put('day_briefs: includes/class.DayBrief.php is missing, so days’ briefs weren’t written.', 6);
    return;
}
if (!defined('OPENAI_KEY') || empty(OPENAI_KEY)) {
    $log->put('day_briefs: OPENAI_KEY is not configured, so days’ briefs weren’t written.', 6);
    return;
}

// Don't ask OpenAI while its spend limit is reached.
$quota = \RsMachine\OpenAiQuota::shared();
if ($quota->pausedUntil() !== null) {
    return $quota->skipped();
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENAI_KEY,
]);

// Whether OpenAI's spend limit was reached during this run.
$exhausted = false;

/*
 * Ask the model. As for bills' narratives, it's a reasoning model, so it takes no temperature, and
 * its reasoning counts against the completion limit.
 */
$complete = function (array $messages) use ($ch, $log, $quota, &$exhausted): ?string {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => DayBrief::MODEL,
        'messages' => $messages,
        'reasoning_effort' => 'low',
        'max_completion_tokens' => 3000,
    ]));
    $response = curl_exec($ch);
    if ($response === false) {
        $log->put('day_briefs: The OpenAI request failed: ' . curl_error($ch), 5);
        return null;
    }
    $decoded = json_decode($response, true);
    if (\RsMachine\OpenAiQuota::isExhausted($decoded)) {
        $quota->recordExhausted($log, 'day_briefs', $decoded);
        $exhausted = true;
        return null;
    }
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (empty($content)) {
        $log->put('day_briefs: OpenAI returned no brief: '
            . ($decoded['error']['message'] ?? ($decoded['choices'][0]['finish_reason'] ?? 'no reason given')), 5);
        return null;
    }
    $quota->clear();
    return $content;
};

$written = 0;
for ($i = 1; $i <= 3 && !$exhausted; $i++) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $day = Day::record_for_api($date);
    if ($day === null || !$day['in_session']) {
        continue;
    }
    $input = DayBrief::input($day);
    if ($input === null) {
        continue;
    }
    $hash = DayBrief::hash($input);
    if (DayBrief::stored_hash($date) === $hash) {
        continue;
    }

    $brief = DayBrief::write($input, $complete);
    if ($brief['text'] === null) {
        continue;
    }
    DayBrief::save($date, $brief, $hash);
    $written++;
    if (count($brief['problems']) > 0) {
        $log->put('day_briefs: The brief of ' . $date . ' failed its checks, so it won’t be shown: '
            . implode(' ', $brief['problems']), 5);
    }
}

if ($exhausted) {
    return $quota->skipped();
}
$log->put('day_briefs: Wrote ' . $written . ' days’ briefs.', $written > 0 ? 3 : 1);
