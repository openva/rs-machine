<?php

/**
 * TASK MANIFEST
 *
 * The single source of truth for what update tasks exist, when they run, and how long they may
 * take. This replaces two overlapping sources that had to be kept in sync by hand:
 *
 *   1. The chain of `if ($type == 'all' || $type == 'foo')` statements in update.php, which
 *      decided what an "all" run covered.
 *   2. The individual `update.php <type>` lines in deploy/crontab.txt, which decided what ran
 *      on its own schedule.
 *
 * Because nothing enforced agreement between the two, seven types ended up both inside "all"
 * and separately scheduled: summaries, full_text, code_sections, download_pdfs, dockets,
 * meetings and fiscal_impact. Those are now marked `in_all => false` — they have their own
 * schedule, so "all" no longer duplicates them.
 *
 * KEYS
 *
 *   file      Task file in cron/, relative to this directory. Defaults to "<name>.php".
 *   schedule  Cron expression for when this task runs on its own, or null for "only via all".
 *             Parsed by dragonmantank/cron-expression, so the same syntax a crontab accepts.
 *   in_all    Whether a bare `run.php all` includes this task.
 *   timeout   Wall-clock ceiling in seconds, applied per task via set_time_limit().
 *   prefetch  CSV files to download from LIS before the task runs, as
 *             [remote LIS filename => local filename in cron/].
 *   comment   Why this task is scheduled the way it is. Kept here so the reasoning travels
 *             with the schedule instead of living in crontab comments.
 *
 * INVARIANT: no task may have both a non-null `schedule` and `in_all => true`. That is the
 * duplication this manifest exists to prevent, and TaskManifestTest asserts it.
 *
 * THE "ALL" PASS
 *
 * Tasks with `in_all => true` have no schedule of their own; they run together as one pass, on the
 * schedule given by the `@all` key below. Keeping that schedule here rather than in the crontab is
 * what lets deploy/crontab.txt collapse to a single heartbeat line — `run.php --due` consults this
 * file for everything, including when the "all" pass is due.
 */

return [

    /*
     * When the combined "all" pass runs. Hourly at :01 during the hours LIS publishes, matching
     * the schedule the bare `update.php` invocation used. The leading @ marks this as
     * configuration rather than a task; run.php reads it separately.
     */
    '@all' => '1 6-23 * * *',


    /*
     * ---- Bill data -------------------------------------------------------------------------
     */

    'bills' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 600,
        'prefetch' => [
            'BILLS.CSV'   => 'bills.csv',
            'Sponsors.csv' => 'sponsors.csv',
        ],
        'comment'  => 'LIS publishes at 6:51 AM; the hourly "all" run picks it up. bills.php '
                    . 'refuses to reparse a CSV younger than 50 minutes, so running it more '
                    . 'often than hourly accomplishes nothing.',
    ],

    'history' => [
        'schedule' => '3,13,23,33,43,53 6-23 * * *',
        'in_all'   => false,
        'timeout'  => 540,
        'comment'  => 'Every 10 minutes during session hours. The timeout is deliberately under '
                    . 'the 10-minute interval: update.php used a single global 1200s ceiling, so '
                    . 'a long run would silently eat the next two ticks. Fails fast instead.',
    ],

    'summaries' => [
        'schedule' => '2 6-23 * * *',
        'in_all'   => false,
        'timeout'  => 300,
        'prefetch' => ['Summaries.csv' => 'summaries.csv'],
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only.',
    ],

    'full_text' => [
        'schedule' => '11,31,51 6-23 * * *',
        'in_all'   => false,
        'timeout'  => 600,
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only. Scrapes LIS '
                    . 'HTML, so subject to the 15-request/30-second rate limit.',
    ],

    'code_sections' => [
        'schedule' => '5,15,25,35,45,55 * * 1-3,10-12 *',
        'in_all'   => false,
        'timeout'  => 480,
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only. Session months '
                    . 'only (Jan-Mar, Oct-Dec).',
    ],

    'copatrons' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
    ],

    /*
     * ---- Legislative activity --------------------------------------------------------------
     */

    'vote' => [
        'schedule' => '4 10,13,16,21 * * *',
        'in_all'   => false,
        'timeout'  => 600,
        'comment'  => 'Four times daily, after each likely floor session.',
    ],

    'dockets' => [
        'schedule' => '9 6-15 * * *',
        'in_all'   => false,
        'timeout'  => 300,
        'prefetch' => ['DOCKET.CSV' => 'docket.csv'],
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only.',
    ],

    'meetings' => [
        'schedule' => '3,33 6-23 * * *',
        'in_all'   => false,
        'timeout'  => 300,
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only.',
    ],

    'minutes' => [
        'schedule' => '4 16,20 * * *',
        'in_all'   => false,
        'timeout'  => 600,
        'comment'  => 'After each chamber posts floor minutes.',
    ],

    /*
     * ---- Legislators -----------------------------------------------------------------------
     */

    'legislators' => [
        'schedule' => '41 12 * * *',
        'in_all'   => false,
        'timeout'  => 900,
        'comment'  => 'Checks for missing/excess legislators. Deliberately not in "all" — it '
                    . 'deactivates records, so it should run on a predictable daily cadence.',
    ],

    'representatives_table' => [
        'schedule' => '31 3 * * *',
        'in_all'   => false,
        'timeout'  => 300,
        'comment'  => 'Syncs the legacy representatives table from people + terms.',
    ],

    'partisanship' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
    ],

    /*
     * ---- Fiscal ----------------------------------------------------------------------------
     */

    'fiscal_impact' => [
        'schedule' => '53 11,13,15,17,19,21 * * *',
        'in_all'   => false,
        'timeout'  => 300,
        'prefetch' => ['FiscalImpactStatements.csv' => 'FiscalImpactStatements.csv'],
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only.',
    ],

    'summarize_fis' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 900,
        'comment'  => 'Calls OpenAI; the generous timeout absorbs API latency.',
    ],

    /*
     * ---- Analysis and categorisation -------------------------------------------------------
     */

    'bills_status_narratives' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 900,
        'comment'  => 'Calls OpenAI.',
    ],

    'tags' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
    ],

    'photosynthesis' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
        'comment'  => 'Smart portfolio repopulation. The only task whose body lived inline in '
                    . 'update.php rather than in its own file; see cron/tasks/photosynthesis.php.',
    ],

    /*
     * ---- Maintenance -----------------------------------------------------------------------
     */

    'cleanup' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 900,
        'comment'  => 'The largest task file (838 lines) and the slowest; hence the wide ceiling.',
    ],

    'articles' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
    ],

    'cache' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
    ],

    'checks' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 300,
        'comment'  => 'Data integrity checks. Reports problems; does not mutate.',
    ],

    'download_pdfs' => [
        'schedule' => '41 2,16 * * *',
        'in_all'   => false,
        'timeout'  => 900,
        'comment'  => 'Was both in "all" and scheduled here. Now scheduled only.',
    ],

    'export' => [
        'schedule'       => '30 2 * * *',
        'in_all'         => false,
        'timeout'        => 1200,
        'needs_database' => false,
        'comment'        => 'Nightly flat-file export, and the one task that tolerates a dead '
                          . 'database connection: it writes flat files from data fetched over '
                          . 'HTTP, so it can produce partial output regardless. update.php '
                          . 'hardcoded this exception; it is now declared here.',
    ],

    'log_prune' => [
        'schedule' => null,
        'in_all'   => true,
        'timeout'  => 120,
        'comment'  => 'Runs last in an "all" pass so it prunes that run\'s own debug output.',
    ],
];
