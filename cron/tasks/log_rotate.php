<?php

/**
 * ROTATE THE LOG FILE
 *
 * Archives logs/site.log, compressed, as logs/site.log-<date>.gz, and deletes archives more than
 * 30 days old (see RsMachine\LogRotation). log_prune does the same for the database's logs table.
 */

declare(strict_types=1);

use RsMachine\LogRotation;
use RsMachine\TaskContext;
use RsMachine\TaskResult;

return function (TaskContext $ctx): TaskResult {

    $keep_days = 30;
    $file = dirname($ctx->cronDir) . '/logs/site.log';

    $size = is_file($file) ? (int) filesize($file) : 0;
    $archive = LogRotation::rotate($file, $keep_days, new DateTimeImmutable());
    if ($archive === null) {
        return TaskResult::skipped('the log is empty');
    }

    $ctx->log->put(
        'log_rotate: Archived ' . round($size / 1048576, 1) . ' MB of log as ' . basename($archive) . '.',
        2
    );

    return TaskResult::completed();
};
