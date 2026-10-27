<?php

declare(strict_types=1);

namespace RsMachine;

use Closure;
use Cron\CronExpression;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * Runs update tasks, owning the concerns that were previously absent or duplicated per-task:
 * locking, per-task timeouts, timing, failure isolation and structured logging.
 *
 * The single most important behavioural change from update.php's `if`-chain is failure isolation.
 * Previously a fatal in task 20 of an "all" pass killed tasks 21-25 silently, and the closing
 * "Updated all" log line never fired — the absence of a success message was the only signal. Here
 * each task runs inside try/catch, so one failure is logged and the pass continues.
 *
 * The second is locking. update.php took no lock at all; the crontab wrapped each invocation in
 * `flock` against a *per-invocation* lockfile, which meant the "all" run and an individually
 * scheduled run of the same type took different locks and so did not exclude each other. Here the
 * lock is keyed on the task name and acquired by the runner, so "all" doing summaries and the
 * scheduled summaries job contend for the same lock no matter how they were invoked.
 */
class TaskRunner
{
    /** Where lockfiles live. /run/lock is tmpfs, so stale locks clear on reboot. */
    private const LOCK_DIR = '/run/lock';

    /**
     * Manifest keys beginning with @ are configuration, not tasks. Splitting them out in the
     * constructor means the rest of this class — and every consumer that iterates the manifest —
     * can treat it as a plain list of tasks without needing to filter.
     */
    private const CONFIG_PREFIX = '@';

    /** The schedule for the combined "all" pass, from the manifest's `@all` key. */
    public readonly ?string $allSchedule;

    /** @var array<string, array<string, mixed>> The manifest, keyed by task name. */
    private array $manifest = [];

    /** @var array<int, array<string, mixed>> One entry per task run, for the summary line. */
    private array $ledger = [];

    /**
     * @param array<string, mixed> $manifest Contents of cron/tasks.php, tasks and config together.
     */
    public function __construct(
        array $manifest,
        private TaskContext $context,
        private string $cronDir
    ) {
        [$this->manifest, $config] = self::partitionManifest($manifest);

        $this->allSchedule = $config['@all'] ?? null;
    }

    /**
     * The task definitions, with configuration keys removed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tasks(): array
    {
        return $this->manifest;
    }

    /**
     * Split a raw manifest into [tasks, config] without needing a TaskContext.
     *
     * The introspection modes (--list, --crontab) and the manifest tests need the tasks-only view
     * but have no database and no reason to build a context, so this is exposed statically.
     *
     * @param  array<string, mixed> $manifest
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>}
     */
    public static function partitionManifest(array $manifest): array
    {
        $tasks  = [];
        $config = [];

        foreach ($manifest as $key => $value) {
            if (str_starts_with((string) $key, self::CONFIG_PREFIX)) {
                $config[$key] = $value;
                continue;
            }
            $tasks[$key] = $value;
        }

        return [$tasks, $config];
    }

    /**
     * Task names that a bare "all" pass covers, in manifest order.
     *
     * Ordering is manifest order rather than anything computed: log_prune sits last in the
     * manifest so it prunes the current run's own debug output, and cleanup runs after the
     * parsers that create the rows it tidies.
     *
     * @return array<int, string>
     */
    public function tasksInAll(): array
    {
        $names = [];
        foreach ($this->manifest as $name => $task) {
            if (($task['in_all'] ?? false) === true) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Task names whose schedule is due at the given time.
     *
     * Uses dragonmantank/cron-expression when available. That package is the one piece of this
     * harness worth taking off the shelf — it is ~30KB with no dependencies and correctly handles
     * step values, ranges and lists, which a hand-rolled parser reliably gets wrong.
     *
     * When the manifest's `@all` schedule is due, the whole "all" pass is expanded inline, so a
     * single `run.php --due` heartbeat covers both individually-scheduled tasks and the combined
     * pass. Tasks are returned in manifest order regardless of which schedule selected them.
     *
     * @param  DateTimeInterface $now The minute to test against.
     * @return array<int, string>
     */
    public function tasksDueAt(DateTimeInterface $now): array
    {
        if (!class_exists(CronExpression::class)) {
            $this->context->log->put(
                'dragonmantank/cron-expression is not installed, so --due cannot determine what '
                . 'is scheduled. Run `composer require dragonmantank/cron-expression`.',
                8
            );
            return [];
        }

        $due = [];

        if ($this->allSchedule !== null && $this->isDue($this->allSchedule, '@all', $now)) {
            $due = $this->tasksInAll();
        }

        foreach ($this->manifest as $name => $task) {
            $schedule = $task['schedule'] ?? null;
            if ($schedule === null) {
                continue;
            }

            try {
                if ((new CronExpression($schedule))->isDue($now)) {
                    $due[] = $name;
                }
            } catch (Throwable $e) {
                /*
                 * A malformed expression must not take down the whole tick, or one typo in the
                 * manifest silently stops every other scheduled task.
                 */
                $this->context->log->put(
                    'Task "' . $name . '" has an unparseable schedule "' . $schedule . '": '
                    . $e->getMessage(),
                    7
                );
            }
        }

        /*
         * Deduplicate and restore manifest order. A task cannot legitimately arrive twice — the
         * manifest invariant forbids being both scheduled and in "all" — but ordering still
         * matters, because "all" expands to a list that must keep log_prune last.
         */
        $due = array_unique($due);

        return array_values(array_filter(
            array_keys($this->manifest),
            static fn(string $name): bool => in_array($name, $due, true)
        ));
    }

    /**
     * Whether a cron expression is due, logging rather than throwing on a malformed one.
     */
    private function isDue(string $schedule, string $label, DateTimeInterface $now): bool
    {
        try {
            return (new CronExpression($schedule))->isDue($now);
        } catch (Throwable $e) {
            $this->context->log->put(
                $label . ' has an unparseable schedule "' . $schedule . '": ' . $e->getMessage(),
                7
            );
            return false;
        }
    }

    /**
     * Run a list of tasks in order, isolating failures.
     *
     * @param  array<int, string> $names
     * @return bool True when every task either completed or skipped.
     */
    public function runMany(array $names): bool
    {
        $ok = true;
        foreach ($names as $name) {
            if (!$this->run($name)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Run a single task: acquire its lock, prefetch its CSVs, invoke it, time it, log it.
     *
     * @return bool True when the task completed, skipped, or could not get its lock. False only
     *              on genuine failure — a contended lock is normal operation, not an error.
     */
    public function run(string $name): bool
    {
        if (!isset($this->manifest[$name])) {
            $this->context->log->put('Unknown task "' . $name . '".', 7);
            return false;
        }

        $task = $this->manifest[$name];

        /*
         * Take the lock before doing anything else, including the CSV prefetch — otherwise two
         * concurrent runs would both download the same file and race on the write.
         */
        $lock = $this->acquireLock($name);
        if ($lock === null) {
            $this->context->log->put('Task "' . $name . '" is already running; skipping.', 2);
            $this->ledger[] = ['task' => $name, 'status' => 'locked', 'ms' => 0];
            return true;
        }

        $started = hrtime(true);

        try {
            if ($this->needsDatabase($name) && $this->context->db === null) {
                throw new RuntimeException('no database connection');
            }

            $this->prefetch($name, $task);

            /*
             * Per-task ceiling, replacing update.php's single global set_time_limit(1200). The
             * old ceiling exceeded some tasks' own scheduling interval, so a slow run would eat
             * subsequent ticks; the manifest keeps each timeout under its task's interval.
             */
            set_time_limit((int) ($task['timeout'] ?? 600));

            $result = $this->invoke($name, $task);
            $ms     = (int) ((hrtime(true) - $started) / 1e6);

            $this->logResult($name, $result, $ms);
            $this->ledger[] = ['task' => $name, 'status' => $result->status, 'ms' => $ms];

            return !$result->isFailure();
        } catch (Throwable $e) {
            /*
             * Catching Throwable rather than Exception is deliberate: a TypeError or a failed
             * assertion in one task must not abort the remaining tasks in an "all" pass.
             */
            $ms = (int) ((hrtime(true) - $started) / 1e6);
            $this->context->log->put(
                'Task "' . $name . '" threw ' . $e::class . ' after ' . $ms . 'ms: '
                . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
                7
            );
            $this->ledger[] = ['task' => $name, 'status' => 'threw', 'ms' => $ms];

            return false;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Invoke the task itself, supporting both the converted and unconverted forms.
     *
     * This dual path is what makes the migration incremental. A task file that returns a closure
     * is called with the context and must return a TaskResult. A task file that is still a bare
     * procedural script is `require`d exactly as update.php did, with the legacy variables it
     * expects extracted into scope first. Nothing has to be converted before this runner is
     * useful, and files can be converted one at a time.
     */
    private function invoke(string $name, array $task): TaskResult
    {
        $file = $this->cronDir . '/' . ($task['file'] ?? $name . '.php');

        /*
         * Prefer a converted task in cron/tasks/ over the legacy file of the same name, so a
         * conversion takes effect by adding a file rather than by editing the manifest.
         */
        $converted = $this->cronDir . '/tasks/' . $name . '.php';
        if (file_exists($converted)) {
            $file = $converted;
        }

        if (!file_exists($file)) {
            return TaskResult::failed('task file ' . basename($file) . ' does not exist');
        }

        $returned = $this->requireIsolated($file);

        if ($returned instanceof Closure) {
            $result = $returned($this->context);

            if (!$result instanceof TaskResult) {
                return TaskResult::failed(
                    'task closure returned ' . get_debug_type($result) . ', expected TaskResult'
                );
            }

            return $result;
        }

        /*
         * Legacy path: the file ran for its side effects. `require` yields int 1 on a plain
         * fall-through and the file's value on a file-scope `return`. A legacy file can return a
         * TaskResult directly, which is how one says "nothing to do" without being reported as a
         * failure. Otherwise `false` is the one signal old task files gave us for "bailed out" —
         * several use `return false;` for an unreadable input file. Everything else is
         * indistinguishable from success.
         */
        if ($returned instanceof TaskResult) {
            return $returned;
        }

        if ($returned === false) {
            return TaskResult::failed('task returned false');
        }

        return TaskResult::completed();
    }

    /**
     * `require` a legacy task file with the variables it expects in scope.
     *
     * Legacy files read $log, $dbh, $session_id and friends from their includer's scope. Those
     * are extracted here rather than left to leak from the runner's own locals, so the set of
     * variables a legacy task can see is explicit and greppable.
     *
     * $GLOBALS['db'] and $GLOBALS['dbh'] are also set, because some tasks reach for the global
     * directly instead of the local.
     *
     * @return mixed Whatever the file returns.
     */
    private function requireIsolated(string $taskFile): mixed
    {
        /*
         * These locals exist to be captured by the required file's scope, not to be read here.
         * The names match what the legacy files already expect, which is why they are snake_case
         * and why $mc and $dlas_session_id look redundant next to the context object.
         *
         * phpcs:disable
         */
        $log             = $this->context->log;
        $dbh             = $this->context->dbh;
        $db              = $this->context->db;
        $mc              = $this->context->memcached;
        $session_id      = $this->context->sessionId;
        $session_year    = $this->context->sessionYear;
        $dlas_session_id = $this->context->lisSessionId;
        $session_api_id  = $this->context->lisApiSessionId;
        // phpcs:enable

        $GLOBALS['db']  = $db;
        $GLOBALS['dbh'] = $dbh;

        return require $taskFile;
    }

    /**
     * Whether a task requires a live mysqli connection.
     *
     * update.php special-cased `export` when the PDO connect failed, dying for every other type.
     * That exception is preserved, but as a manifest property rather than a hardcoded task name:
     * export.php writes flat files from data it fetches over HTTP and can produce partial output
     * without the database.
     *
     * Defaults to true, so a new task is protected unless it opts out.
     */
    protected function needsDatabase(string $name): bool
    {
        return ($this->manifest[$name]['needs_database'] ?? true) === true;
    }

    /**
     * Download this task's declared CSV files from LIS.
     *
     * Previously each CSV was a separate crontab line scheduled a minute before its consumer,
     * with the pairing implied only by adjacency in the file. Declaring it on the task makes the
     * dependency explicit and impossible to schedule inconsistently.
     */
    private function prefetch(string $name, array $task): void
    {
        $files = $task['prefetch'] ?? [];
        if ($files === []) {
            return;
        }

        $script = $this->cronDir . '/fetch_csv.sh';

        foreach ($files as $remote => $local) {
            $command = escapeshellcmd('/bin/bash') . ' ' . escapeshellarg($script) . ' '
                     . escapeshellarg($remote) . ' ' . escapeshellarg($this->cronDir . '/' . $local);

            exec($command . ' 2>&1', $output, $status);

            if ($status !== 0) {
                /*
                 * A failed fetch is logged but not fatal: the parsers all hash their input and
                 * skip unchanged files, so a task running against yesterday's CSV is a no-op
                 * rather than a corruption. Escalating here would turn a transient LIS outage
                 * into a failed run.
                 */
                $this->context->log->put(
                    'Prefetch of ' . $remote . ' for task "' . $name . '" exited ' . $status
                    . ': ' . implode(' ', array_slice($output, -3)),
                    6
                );
            }
        }
    }

    /**
     * Acquire an exclusive, non-blocking lock for a task.
     *
     * Keyed on task name, so an "all" pass and a scheduled run of the same task exclude one
     * another — the gap the crontab's per-invocation flock left open.
     *
     * @return resource|null The open handle, or null when another run holds the lock.
     */
    private function acquireLock(string $name)
    {
        $dir = is_dir(self::LOCK_DIR) && is_writable(self::LOCK_DIR)
            ? self::LOCK_DIR
            : sys_get_temp_dir();

        $handle = fopen($dir . '/rs-task-' . $name . '.lock', 'c');
        if ($handle === false) {
            /*
             * Fail open rather than closed: if the lock file cannot be created, running the task
             * unlocked is better than silently never running it again.
             */
            $this->context->log->put(
                'Could not open lockfile for task "' . $name . '"; running unlocked.',
                6
            );
            return fopen('php://memory', 'r+') ?: null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Log a task's outcome at a level matching its severity, with its duration.
     *
     * Per-task timing did not previously exist at any level; the only output was a single
     * "Updated <type>" line covering an entire pass.
     */
    private function logResult(string $name, TaskResult $result, int $ms): void
    {
        $metrics = $result->metricsSummary();
        $suffix  = $metrics === '' ? '' : ' [' . $metrics . ']';

        match ($result->status) {
            TaskResult::COMPLETED => $this->context->log->put(
                'Task "' . $name . '" completed in ' . $ms . 'ms' . $suffix,
                2
            ),
            TaskResult::SKIPPED => $this->context->log->put(
                'Task "' . $name . '" skipped after ' . $ms . 'ms: ' . $result->reason,
                2
            ),
            TaskResult::FAILED => $this->context->log->put(
                'Task "' . $name . '" FAILED after ' . $ms . 'ms: ' . $result->reason,
                7
            ),
            default => $this->context->log->put(
                'Task "' . $name . '" returned unknown status "' . $result->status . '"',
                6
            ),
        };
    }

    /**
     * One line summarising a multi-task pass, so a whole "all" run is greppable as a unit.
     */
    public function summary(): string
    {
        if ($this->ledger === []) {
            return 'no tasks ran';
        }

        $total = 0;
        $parts = [];
        foreach ($this->ledger as $entry) {
            $total += $entry['ms'];
            $parts[] = $entry['task'] . '=' . $entry['status'] . '/' . $entry['ms'] . 'ms';
        }

        return count($this->ledger) . ' tasks in ' . $total . 'ms: ' . implode(' ', $parts);
    }
}
