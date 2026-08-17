<?php

/**
 * UPDATE DASHBOARD SMART PORTFOLIOS
 *
 * Steps through every smart portfolio and repopulates its constituent bills.
 *
 * This task's body previously lived inline in update.php rather than in its own file, so it is
 * the natural first conversion — there was no legacy file to preserve. It also serves as the
 * reference example of the converted form:
 *
 *   - Returns a closure taking a TaskContext and returning a TaskResult.
 *   - Reads its dependencies off $ctx rather than from the includer's variable scope.
 *   - Reports counts via TaskResult::completed(), so the runner can log how much work happened
 *     instead of just that the task finished.
 *
 * When converting a legacy task, alias the context properties to the variable names the file
 * already uses ($log, $session_id, $mc) at the top of the closure. That keeps the diff at the top
 * of the file rather than renaming variables throughout.
 *
 * Note the two `use` statements: task files run in the global namespace, while the harness classes
 * live in RsMachine\ (in src/, not includes/ — see AGENTS.md for why). Every converted task needs
 * these two lines.
 */

declare(strict_types=1);

use RsMachine\TaskContext;
use RsMachine\TaskResult;

return function (TaskContext $ctx): TaskResult {

    if (!function_exists('populate_smart_portfolio')) {
        return TaskResult::failed('populate_smart_portfolio() is unavailable; is '
            . 'includes/photosynthesis.inc.php loaded?');
    }

    $sql = 'SELECT id
            FROM dashboard_portfolios
            WHERE watch_list_id IS NOT NULL';

    $result = mysqli_query($ctx->db, $sql);

    if ($result === false) {
        return TaskResult::failed('could not query dashboard_portfolios: '
            . mysqli_error($ctx->db));
    }

    /*
     * A site with no smart portfolios is a legitimate state, not a failure — reporting it as a
     * skip rather than a zero-count completion keeps "nothing to do" distinguishable from
     * "did the work, and the work was empty".
     */
    if (mysqli_num_rows($result) === 0) {
        return TaskResult::skipped('no smart portfolios are defined');
    }

    $updated = 0;
    $failed  = 0;

    while ($portfolio = mysqli_fetch_array($result)) {
        /*
         * One bad portfolio must not abort the rest. The inline version in update.php had no
         * guard here, so a single failing portfolio would take down every task scheduled after
         * it in the "all" pass.
         */
        try {
            populate_smart_portfolio($portfolio['id']);
            $updated++;
        } catch (Throwable $e) {
            $failed++;
            $ctx->log->put(
                'Could not populate smart portfolio ' . $portfolio['id'] . ': ' . $e->getMessage(),
                6
            );
        }
    }

    if ($failed > 0 && $updated === 0) {
        return TaskResult::failed('every one of the ' . $failed . ' portfolios failed');
    }

    return TaskResult::completed(['portfolios_updated' => $updated, 'portfolios_failed' => $failed]);
};
