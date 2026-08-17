<?php

declare(strict_types=1);

use Cron\CronExpression;
use PHPUnit\Framework\TestCase;
use RsMachine\TaskRunner;

/**
 * Guards the task manifest's invariants.
 *
 * The point of the manifest is that the schedule and the "all" membership stop being two
 * independently-maintained lists that drift apart. These tests are what make that structural
 * rather than aspirational — without them, the next person to add a task can reintroduce exactly
 * the duplication the manifest was written to remove.
 */
final class TaskManifestTest extends TestCase
{
    /** @var array<string, array<string, mixed>> Task definitions, @-prefixed config removed. */
    private static array $manifest;

    /** @var array<string, mixed> The @-prefixed configuration keys. */
    private static array $config;

    public static function setUpBeforeClass(): void
    {
        [self::$manifest, self::$config] = TaskRunner::partitionManifest(
            require __DIR__ . '/../../cron/tasks.php'
        );
    }

    /**
     * The "all" pass needs a schedule, or the tasks marked in_all would never run: the crontab
     * heartbeat invokes `--due`, which relies entirely on the manifest to know what is due.
     */
    public function testAllPassHasASchedule(): void
    {
        $this->assertArrayHasKey(
            '@all',
            self::$config,
            'The manifest needs an @all key giving the schedule for the combined pass.'
        );

        $this->assertTrue(
            CronExpression::isValidExpression(self::$config['@all']),
            'The @all schedule is not a valid cron expression: ' . self::$config['@all']
        );
    }

    /**
     * THE central invariant. A task with its own schedule must not also be swept up by "all",
     * because that is precisely the double-execution the old update.php/crontab split produced:
     * seven types (summaries, full_text, code_sections, download_pdfs, dockets, meetings,
     * fiscal_impact) ran both ways and could overlap themselves.
     */
    public function testNoTaskIsBothScheduledAndInAll(): void
    {
        $violations = [];

        foreach (self::$manifest as $name => $task) {
            if (($task['schedule'] ?? null) !== null && ($task['in_all'] ?? false) === true) {
                $violations[] = $name;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'These tasks have their own schedule AND are in "all", so they would run twice: '
            . implode(', ', $violations)
        );
    }

    /**
     * Every task must be reachable somehow. A task that is neither scheduled nor in "all" is
     * dead config that will never execute.
     */
    public function testEveryTaskIsReachable(): void
    {
        foreach (self::$manifest as $name => $task) {
            $reachable = ($task['schedule'] ?? null) !== null || ($task['in_all'] ?? false) === true;

            $this->assertTrue(
                $reachable,
                'Task "' . $name . '" is neither scheduled nor in "all", so it never runs.'
            );
        }
    }

    /**
     * Every task must have a file to execute, in either the converted or the legacy location.
     */
    public function testEveryTaskHasAFile(): void
    {
        $cronDir = __DIR__ . '/../../cron';

        foreach (self::$manifest as $name => $task) {
            $legacy    = $cronDir . '/' . ($task['file'] ?? $name . '.php');
            $converted = $cronDir . '/tasks/' . $name . '.php';

            $this->assertTrue(
                file_exists($legacy) || file_exists($converted),
                'Task "' . $name . '" has no file at ' . $legacy . ' or ' . $converted
            );
        }
    }

    /**
     * A timeout that exceeds the task's own scheduling interval means a slow run silently
     * cannibalises later ticks. This is the concrete bug the old single global
     * set_time_limit(1200) caused for `history`, which is scheduled every 10 minutes.
     */
    public function testTimeoutFitsInsideSchedulingInterval(): void
    {
        if (!class_exists(CronExpression::class)) {
            $this->markTestSkipped('dragonmantank/cron-expression is not installed.');
        }

        foreach (self::$manifest as $name => $task) {
            $schedule = $task['schedule'] ?? null;
            if ($schedule === null) {
                continue;
            }

            $expression = new CronExpression($schedule);
            $reference  = new DateTimeImmutable('2026-02-10 09:00:00');

            $first  = $expression->getNextRunDate($reference);
            $second = $expression->getNextRunDate($first);
            $gap    = $second->getTimestamp() - $first->getTimestamp();

            $this->assertLessThanOrEqual(
                $gap,
                $task['timeout'] ?? 600,
                'Task "' . $name . '" has a ' . ($task['timeout'] ?? 600) . 's timeout but runs '
                . 'every ' . $gap . 's, so a slow run would skip the next tick.'
            );
        }
    }

    /**
     * Schedules must actually parse. A typo here would otherwise only surface as a task that
     * quietly never runs.
     */
    public function testEveryScheduleParses(): void
    {
        if (!class_exists(CronExpression::class)) {
            $this->markTestSkipped('dragonmantank/cron-expression is not installed.');
        }

        foreach (self::$manifest as $name => $task) {
            $schedule = $task['schedule'] ?? null;
            if ($schedule === null) {
                continue;
            }

            $this->assertTrue(
                CronExpression::isValidExpression($schedule),
                'Task "' . $name . '" has an invalid cron expression: "' . $schedule . '"'
            );
        }
    }

    /**
     * Prefetch declarations must name the fetch script's two arguments, and the local filename
     * must be a bare name rather than a path — the runner joins it to cron/ itself.
     */
    public function testPrefetchTargetsAreBareFilenames(): void
    {
        foreach (self::$manifest as $name => $task) {
            foreach ($task['prefetch'] ?? [] as $remote => $local) {
                $this->assertIsString($remote, 'Task "' . $name . '" has a non-string CSV name.');
                $this->assertSame(
                    basename($local),
                    $local,
                    'Task "' . $name . '" prefetches to "' . $local . '"; use a bare filename.'
                );
            }
        }
    }

    /**
     * The set of tasks in "all" is load-bearing for the nightly pass, so changes to it should be
     * deliberate. log_prune must stay last so it prunes the current run's own output.
     */
    public function testLogPruneRunsLastInAll(): void
    {
        $inAll = [];
        foreach (self::$manifest as $name => $task) {
            if (($task['in_all'] ?? false) === true) {
                $inAll[] = $name;
            }
        }

        $this->assertSame(
            'log_prune',
            end($inAll),
            'log_prune should be the last task in "all" so it prunes that run\'s own log output.'
        );
    }
}
