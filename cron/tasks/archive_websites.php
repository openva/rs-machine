<?php

/**
 * ARCHIVE LEGISLATORS' WEBSITES
 *
 * Asks the Wayback Machine to archive each current legislator's website (terms.url): monthly, and
 * weekly from May through November of their chamber's election years (see
 * RsMachine\WebsiteArchiving). Each request archives the home page and up to 100 pages it links to.
 *
 * To spare the Wayback Machine, each run submits at most one site, and only when the account has a
 * capture session free. Run every 15 minutes, that's 96 sites a day.
 *
 * When each site was last submitted is kept in cron/website-archives.json. A capture that fails,
 * or that the Wayback Machine refuses, isn't retried until the site is next due. (The website shows when each site was last archived by
 * asking the Wayback Machine's CDX API, not from this file.)
 */

declare(strict_types=1);

use RsMachine\TaskContext;
use RsMachine\TaskResult;
use RsMachine\WaybackAuthenticationException;
use RsMachine\WaybackMachine;
use RsMachine\WebsiteArchiving;

return function (TaskContext $ctx): TaskResult {

    $sites_per_run = 1;

    // Whitespace around a key makes SPN2 treat the request as anonymous.
    $access_key = defined('IA_ACCESS_KEY') ? trim((string) IA_ACCESS_KEY) : '';
    $secret_key = defined('IA_SECRET_KEY') ? trim((string) IA_SECRET_KEY) : '';
    if ($access_key === '' || $secret_key === '') {
        return TaskResult::failed('IA_ACCESS_KEY and IA_SECRET_KEY must be set');
    }

    $rows = $ctx->dbh->query(
        "SELECT url, chamber
        FROM terms
        WHERE
            url IS NOT NULL AND url <> '' AND
            date_started <= CURDATE() AND
            (date_ended IS NULL OR date_ended = '0000-00-00' OR date_ended >= CURDATE())
        ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $sites = [];
    foreach ($rows as $row) {
        $url = trim($row['url']);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $ctx->log->put('Not archiving invalid legislator website URL: ' . $row['url'], 4);
            continue;
        }
        $sites[$url] = $row['chamber'];
    }

    if ($sites === []) {
        return TaskResult::skipped('no current legislators have websites');
    }

    $state_file = $ctx->cronPath('website-archives.json');
    $archived = [];
    if (is_readable($state_file)) {
        $archived = json_decode((string) file_get_contents($state_file), true);
        if (!is_array($archived)) {
            return TaskResult::failed('could not parse ' . $state_file);
        }
    }

    // Forget sites that no current legislator has.
    $archived = array_intersect_key($archived, $sites);

    $now = new DateTimeImmutable();
    $due = WebsiteArchiving::due($sites, $archived, $now);

    if ($due === []) {
        file_put_contents($state_file, json_encode($archived, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return TaskResult::skipped('no websites are due');
    }

    $wayback = new WaybackMachine($access_key, $secret_key);
    try {
        $available = $wayback->availableSessions();
    } catch (WaybackAuthenticationException $e) {
        return TaskResult::failed($e->getMessage());
    } catch (RuntimeException $e) {
        // The Wayback Machine is sometimes down for days; that isn't worth an alert every run.
        return TaskResult::skipped('the Wayback Machine is unavailable: ' . $e->getMessage());
    }
    if ($available < 1) {
        return TaskResult::skipped(count($due) . ' websites are due, but the Wayback Machine has '
            . 'no capture sessions free');
    }

    $submitted = 0;
    foreach (array_slice($due, 0, min($sites_per_run, $available)) as $url) {
        try {
            $job_id = $wayback->saveWithOutlinks($url);
            $submitted++;
            $ctx->log->put('Submitted ' . $url . ' to the Wayback Machine (job ' . $job_id . ').', 2);
        } catch (WaybackAuthenticationException $e) {
            // SPN2 occasionally says "You need to be logged in" to a capture request just after
            // accepting the same keys for availableSessions(). Not the site's fault, so it isn't
            // recorded as tried, and it's tried again on the next run.
            $ctx->log->put('The Wayback Machine refused our keys while submitting ' . $url . ': '
                . $e->getMessage(), 4);
            break;
        } catch (UnexpectedValueException $e) {
            // Refused, like a failed capture: tried again when next due, so that a site that's
            // always refused can't hold up the rest.
            $ctx->log->put('The Wayback Machine refused ' . $url . ': ' . $e->getMessage(), 4);
        } catch (RuntimeException $e) {
            // Unreachable: not recorded, so it's tried again on the next run.
            $ctx->log->put('Could not reach the Wayback Machine to submit ' . $url . ': '
                . $e->getMessage(), 4);
            break;
        }

        $archived[$url] = $now->format('c');
    }

    $temp = $state_file . '.tmp';
    if (
        file_put_contents($temp, json_encode($archived, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
        || !rename($temp, $state_file)
    ) {
        return TaskResult::failed('could not write ' . $state_file);
    }

    return TaskResult::completed([
        'submitted' => $submitted,
        'still_due' => count($due) - $submitted,
    ]);
};
