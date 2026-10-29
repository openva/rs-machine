<?php

/*
 * Translate the bill history of past sessions into statuses, and update their bills' statuses.
 *
 * cleanup.php does this hourly, but only for the current session, so a session whose history was
 * imported under older translation rules keeps the statuses those rules gave it. This brings it up
 * to date with RsMachine\StatusTranslation. Only untranslated history entries are translated, so
 * it's safe to rerun.
 *
 * Usage: php utilities/translate_statuses.php <session ID> [<session ID> ...]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

$session_ids = array_map('intval', array_slice($argv, 1));
if ($session_ids === [] || in_array(0, $session_ids, true)) {
    fwrite(STDERR, "Usage: php utilities/translate_statuses.php <session ID> [<session ID> ...]\n");
    exit(1);
}

$dbh = (new Database())->connect();
if (!$dbh instanceof PDO) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ($session_ids as $session_id) {
    $translated = \RsMachine\StatusTranslation::apply($dbh, $session_id);
    echo 'Session ' . $session_id . ': translated ' . $translated . " history entries.\n";
}
