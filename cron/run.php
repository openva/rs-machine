<?php

/**
 * TASK RUNNER ENTRY POINT
 *
 * The replacement for update.php's dispatch chain. Usage:
 *
 *   php cron/run.php all              Run every task with in_all => true.
 *   php cron/run.php summaries        Run one task by name.
 *   php cron/run.php bills summaries  Run several, in the order given.
 *   php cron/run.php --due            Run whatever the manifest says is due this minute.
 *   php cron/run.php --list           Print the manifest as a table and exit.
 *   php cron/run.php --crontab        Print the crontab lines this manifest replaces.
 *
 * --due is the mode that lets deploy/crontab.txt collapse to a single heartbeat line:
 *
 *   * * * * * /usr/bin/flock -n -E 0 /run/lock/rs-tick.lock /usr/bin/php rs-machine/cron/run.php --due
 *
 * Exits 0 when every task completed or skipped, 1 when any failed. Cron mails on non-zero output,
 * so a failing task becomes visible without needing to watch the logs.
 */

declare(strict_types=1);

use RsMachine\TaskContext;
use RsMachine\TaskRunner;

/*
 * Warnings are shown here, unlike update.php's error_reporting(E_ERROR). Suppressing warnings hid
 * real problems — a scrape returning malformed data raises warnings, not errors, so the old
 * setting made exactly the failures worth seeing invisible. The runner catches Throwables per
 * task, so a noisy warning cannot take down a whole pass.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    /*
     * update.php accepted ?type= over HTTP, which meant anything that could reach the web server
     * could trigger an arbitrary update task. There is no reason for this to be reachable that
     * way; the runner is CLI-only.
     */
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/photosynthesis.inc.php';

/*
 * TaskRunner, TaskContext and TaskResult live in src/ and are autoloaded via the RsMachine\
 * PSR-4 mapping. They are deliberately NOT in includes/, which is wiped and repopulated from the
 * richmondsunlight.com repository on every deploy — see the note in AGENTS.md.
 */
require_once __DIR__ . '/../includes/vendor/autoload.php';

$manifest = require __DIR__ . '/tasks.php';
$log      = new Log();

/*
 * ---- Argument parsing ----------------------------------------------------------------------
 */

$argv = $_SERVER['argv'];
array_shift($argv);

if ($argv === []) {
    fwrite(STDERR, "Usage: run.php <all|--due|--list|--crontab|task...>\n");
    exit(1);
}

/*
 * --list and --crontab are introspection modes that need no database, so they run before the
 * connection is attempted, using the static manifest partition rather than a full runner.
 */
if ($argv[0] === '--list' || $argv[0] === '--crontab') {
    [$tasks, $config] = TaskRunner::partitionManifest($manifest);
    $allSchedule      = $config['@all'] ?? null;
    $inAll            = array_keys(array_filter($tasks, static fn($t) => $t['in_all'] ?? false));

    if ($argv[0] === '--list') {
        printf("%-26s %-32s %-7s %s\n", 'TASK', 'SCHEDULE', 'IN ALL', 'TIMEOUT');
        foreach ($tasks as $name => $task) {
            printf(
                "%-26s %-32s %-7s %ds\n",
                $name,
                $task['schedule'] ?? '(via all only)',
                ($task['in_all'] ?? false) ? 'yes' : 'no',
                $task['timeout'] ?? 600
            );
        }
        printf(
            "\nThe \"all\" pass (%d tasks marked IN ALL) runs on: %s\n",
            count($inAll),
            $allSchedule ?? '(no @all schedule set)'
        );
        exit(0);
    }

    echo "# Reference only: the equivalent per-task crontab lines for cron/tasks.php.\n";
    echo "# The real crontab uses a single --due heartbeat instead; see deploy/crontab.txt.\n\n";
    if ($allSchedule !== null) {
        printf(
            "%s /usr/bin/flock -n -E 0 /run/lock/rs-task-all.lock /usr/bin/php rs-machine/cron/run.php all\n",
            $allSchedule
        );
    }
    foreach ($tasks as $name => $task) {
        if (($task['schedule'] ?? null) === null) {
            continue;
        }
        printf(
            "%s /usr/bin/flock -n -E 0 /run/lock/rs-task-%s.lock /usr/bin/php rs-machine/cron/run.php %s\n",
            $task['schedule'],
            $name,
            $name
        );
    }
    exit(0);
}

/*
 * ---- Resolve the task list -----------------------------------------------------------------
 *
 * Deliberately before the database connection: a mistyped task name should produce a clean error,
 * not an obscure failure from inside the connection code.
 */

if ($argv[0] === '--due') {
    $mode = 'due';
} elseif ($argv[0] === 'all') {
    $mode = 'all';
} else {
    $mode = 'named';

    $unknown = array_diff($argv, array_keys($manifest));
    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown task(s): ' . implode(', ', $unknown) . "\n");
        fwrite(STDERR, "Run `php cron/run.php --list` to see valid names.\n");
        exit(1);
    }
}

/*
 * ---- Database connections ------------------------------------------------------------------
 */

/*
 * Database::connect_mysqli() is written for the website, not for cron: on failure it tries to
 * redirect a browser to /site-down/ and calls exit, which in a CLI context means a fatal on the
 * undefined SITE_BASE_URL rather than a usable error. Since that class lives in the shared
 * includes/ repository, the connection is probed here first so this runner can report the failure
 * itself and let TaskRunner decide per task whether the database was required.
 */
/**
 * Open the mysqli and PDO connections, either of which may be null when it cannot be had. It
 * runs at the start, and again by TaskRunner whenever MySQL has closed them partway through a
 * pass (see TaskRunner::reconnectIfClosed).
 *
 * @return array{0: ?mysqli, 1: ?PDO}
 */
$connect = static function () use ($log): array {
    // Database reuses whatever handles are in these globals, which are dead on a reconnect.
    $hadLogConnection = isset($GLOBALS['db_pdo']);
    unset($GLOBALS['db'], $GLOBALS['db_pdo']);

    $db = null;

    $probe = @mysqli_connect(PDO_SERVER, PDO_USERNAME, PDO_PASSWORD);
    if ($probe instanceof mysqli) {
        mysqli_close($probe);

        $database = new Database();
        $database->connect_mysqli();
        $db = $GLOBALS['db'] ?? null;
    } else {
        $log->put('Could not connect to the database via mysqli: ' . mysqli_connect_error(), 8);
    }

    try {
        $dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        /*
         * Unlike update.php, a dead PDO connection is not fatal here. The runner decides per task
         * whether the database is required (see TaskRunner::needsDatabase), so `export` still runs
         * and everything else fails loudly with a clear reason rather than the whole process dying.
         */
        $dbh = null;
        $log->put('Could not connect to the database via PDO: ' . $e->getMessage(), 8);
    }

    // Log writes to the database through this global, when a task has set it.
    if ($hadLogConnection && $dbh !== null) {
        $GLOBALS['db_pdo'] = $dbh;
    }

    return [$db, $dbh];
};

[$db, $dbh] = $connect();

$memcached = null;
if (defined('MEMCACHED_SERVER') && MEMCACHED_SERVER !== '' && class_exists('Memcached')) {
    $memcached = new Memcached();
    $memcached->addServer(MEMCACHED_SERVER, (int) MEMCACHED_PORT);
}

$context = new TaskContext(
    log: $log,
    db: $db instanceof mysqli ? $db : null,
    dbh: $dbh,
    memcached: $memcached,
    sessionId: (int) SESSION_ID,
    sessionYear: (int) SESSION_YEAR,
    lisSessionId: (string) SESSION_LIS_ID,
    /*
     * bills.php skips its LIS legislation-ID lookup entirely when this is empty, silently leaving
     * bills.lis_id unpopulated, so it is tolerated as absent rather than required — but it must be
     * passed through when defined. It is not defined in settings-docker.inc.php.
     */
    lisApiSessionId: defined('SESSION_LIS_API_ID') ? (string) SESSION_LIS_API_ID : '',
    cronDir: __DIR__
);

$runner = new TaskRunner($manifest, $context, __DIR__, $connect);

/*
 * ---- Run -----------------------------------------------------------------------------------
 */

$names = match ($mode) {
    /*
     * `now` uses the server's configured timezone, matching how cron itself interprets the
     * schedules these expressions were copied from.
     */
    'due'   => $runner->tasksDueAt(new DateTimeImmutable('now')),
    'all'   => $runner->tasksInAll(),
    'named' => $argv,
};

/*
 * A tick with nothing scheduled is the common case — 50-odd of every 60 minutes — so it exits
 * silently rather than logging, to avoid burying real messages.
 */
if ($names === []) {
    exit(0);
}

$ok = $runner->runMany($names);

$log->put(
    ($mode === 'due' ? 'Scheduled tick' : 'Run ' . implode(',', $names)) . ': '
    . $runner->summary(),
    2
);

exit($ok ? 0 : 1);
