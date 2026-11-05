<?php

/*
 * Record again the Code sections that past sessions' bills affect.
 *
 * cron/code_sections.php does this only for the current session's bills, so sessions parsed
 * under older rules keep what those rules found. Until November 2026, those rules recorded every
 * section a bill cited, for bills whose text came from the LIS API. This reparses each bill's
 * latest text with RsMachine\CodeSections. It's safe to rerun.
 *
 * Usage: php utilities/parse_code_sections.php <session ID> [<session ID> ...]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

$session_ids = array_map('intval', array_slice($argv, 1));
if ($session_ids === [] || in_array(0, $session_ids, true)) {
    fwrite(STDERR, "Usage: php utilities/parse_code_sections.php <session ID> [<session ID> ...]\n");
    exit(1);
}

$dbh = (new Database())->connect();
if (!$dbh instanceof PDO) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ($session_ids as $session_id) {
    $parsed = \RsMachine\CodeSections::update($dbh, $session_id, [], PHP_INT_MAX);
    echo 'Session ' . $session_id . ': parsed ' . count($parsed) . " bills.\n";
}
