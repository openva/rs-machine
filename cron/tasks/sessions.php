<?php

/**
 * SESSIONS
 *
 * Keeps the sessions table in step with LIS's list of sessions: adding each new session (a regular
 * session as soon as LIS lists its start date, months before it begins, and each special session),
 * and recording when sessions adjourn. See RsMachine\Sessions for the rules.
 *
 * Then publishes every session to downloads/sessions.json, which is copied to
 * https://downloads.richmondsunlight.com/sessions.json hourly. richmondsunlight.com's
 * session-update workflow reads it for the new session's ID, which it can't get otherwise, when it
 * proposes switching the website's settings to that session.
 *
 * A new session is announced in Slack. The website's settings are changed only for a regular
 * session (by that pull request); a special session runs alongside one, so whether to follow it
 * instead is a person's decision.
 */

declare(strict_types=1);

use RsMachine\Sessions;
use RsMachine\TaskContext;
use RsMachine\TaskResult;

return function (TaskContext $ctx): TaskResult {

    if (!defined('LIS_KEY') || LIS_KEY === '') {
        return TaskResult::failed('LIS_KEY must be set');
    }

    $ch = curl_init('https://lis.virginia.gov/Session/api/GetSessionListAsync');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'WebAPIKey: ' . LIS_KEY],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        return TaskResult::failed('the request to LIS’s GetSessionListAsync failed with “'
            . curl_error($ch) . '”');
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code != 200) {
        return TaskResult::failed('LIS’s GetSessionListAsync returned an HTTP ' . $http_code);
    }

    $list = json_decode((string) $response, true);
    if (!is_array($list) || !isset($list['Sessions']) || !is_array($list['Sessions']) || $list['Sessions'] === []) {
        return TaskResult::failed('LIS’s GetSessionListAsync returned no sessions');
    }

    $changes = Sessions::apply($ctx->dbh, $list['Sessions']);

    foreach ($changes['insert'] as $session) {
        $ctx->log->put('Added the ' . Sessions::name($session) . ' (LIS ' . $session['lis_id']
            . '), beginning ' . $session['date_started'] . ', as session ' . $session['id'] . '.'
            . ($session['suffix'] === null
                ? ' A pull request to make it the website’s current session will follow, once '
                    . 'bills may be prefiled for it.'
                : ' The website will keep following the regular session unless its settings are '
                    . 'changed.'), 5);
    }
    foreach ($changes['update'] as $id => $set) {
        $ctx->log->put('Updated session ' . $id . ' from LIS: '
            . implode(', ', array_map(static fn($c, $v) => $c . ' = ' . $v, array_keys($set), $set))
            . '.', 3);
    }

    $downloads_dir = getenv('RS_JSONL_DOWNLOADS_DIR') ?: $ctx->cronDir . '/../downloads';
    $downloads_dir = rtrim($downloads_dir, '/\\');
    if (!is_dir($downloads_dir) && !mkdir($downloads_dir, 0775, true) && !is_dir($downloads_dir)) {
        return TaskResult::failed('could not create ' . $downloads_dir);
    }
    $file = $downloads_dir . '/sessions.json';
    $json = json_encode(Sessions::published($ctx->dbh), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($file . '.tmp', $json) === false || !rename($file . '.tmp', $file)) {
        return TaskResult::failed('could not write ' . $file);
    }

    return TaskResult::completed([
        'added'   => count($changes['insert']),
        'updated' => count($changes['update']),
    ]);
};
