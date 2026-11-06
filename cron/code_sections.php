<?php

/*
 * Record the sections of the Code of Virginia that each bill amends, adds or repeals, from the
 * latest version of its text. The rules are in RsMachine\CodeSections.
 *
 * Which version's sections are stored for each bill is kept in cron/code-sections-parsed.json, so
 * that a bill is parsed again only when a newer version appears, including a bill that turned
 * out to change no sections at all.
 */

use RsMachine\CodeSections;
use RsMachine\StateFile;

$state_file = __DIR__ . '/code-sections-parsed.json';
$before = StateFile::load($state_file);

// Each run parses a few hundred bills, so the first run of a session doesn't take too long. It
// stops after six minutes, well inside its time limit, since its progress is saved only at the end.
$parsed = CodeSections::update($dbh, SESSION_ID, $before, 300, microtime(true) + 360);

if (!StateFile::save($state_file, $parsed, array_keys($parsed))) {
    $log->put('Unable to write ' . $state_file, 5);
}

$count = count(array_diff_assoc($parsed, $before));
if ($count === 0) {
    return \RsMachine\TaskResult::skipped('no bill has a new version of its text');
}

$log->put('Recorded the Code sections that ' . $count . ' bills affect.', 2);
