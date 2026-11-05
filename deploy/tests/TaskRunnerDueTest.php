<?php

use PHPUnit\Framework\TestCase;
use RsMachine\TaskContext;
use RsMachine\TaskRunner;

require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * What the heartbeat starts each minute, and the lock that keeps "all" passes from overlapping.
 */
class TaskRunnerDueTest extends TestCase
{
    private const MANIFEST = [
        '@all'    => '1 6-23 * * *',
        'parsed'  => ['schedule' => null, 'in_all' => true, 'needs_database' => false, 'timeout' => 60],
        'hourly'  => ['schedule' => '1 * * * *', 'in_all' => false, 'needs_database' => false, 'timeout' => 60],
        'later'   => ['schedule' => '2 6-23 * * *', 'in_all' => false, 'needs_database' => false, 'timeout' => 60],
    ];

    private string $cronDir;

    protected function setUp(): void
    {
        $this->cronDir = sys_get_temp_dir() . '/rs-due-test-' . bin2hex(random_bytes(4));
        mkdir($this->cronDir);
        file_put_contents(
            $this->cronDir . '/parsed.php',
            '<?php file_put_contents(' . var_export($this->cronDir . '/ran', true) . ', "yes");'
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cronDir . '/*'));
        rmdir($this->cronDir);
    }

    /**
     * The pass is one job, started alongside the scheduled tasks due the same minute.
     */
    public function testTheAllPassIsOneJob(): void
    {
        $this->assertSame(['all', 'hourly'], $this->runner()->jobsDueAt(new DateTimeImmutable('2026-01-14 06:01')));
    }

    public function testOnlyWhatIsDueIsStarted(): void
    {
        $runner = $this->runner();

        $this->assertSame(['later'], $runner->jobsDueAt(new DateTimeImmutable('2026-01-14 06:02')));
        $this->assertSame(['hourly'], $runner->jobsDueAt(new DateTimeImmutable('2026-01-14 03:01')));
        $this->assertSame([], $runner->jobsDueAt(new DateTimeImmutable('2026-01-14 06:30')));
    }

    public function testTasksDueAtStillExpandsThePass(): void
    {
        $this->assertSame(['parsed', 'hourly'], $this->runner()->tasksDueAt(new DateTimeImmutable('2026-01-14 06:01')));
    }

    public function testThePassRuns(): void
    {
        $this->assertTrue($this->runner()->runAll());
        $this->assertFileExists($this->cronDir . '/ran');
    }

    /**
     * Two passes at once would run their tasks out of order, each skipping what the other held.
     */
    public function testAPassIsSkippedWhileAnotherRuns(): void
    {
        $dir = is_dir('/run/lock') && is_writable('/run/lock') ? '/run/lock' : sys_get_temp_dir();
        $held = fopen($dir . '/rs-task-all.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB), 'Another "all" pass is running here.');

        try {
            $this->assertTrue($this->runner()->runAll());
            $this->assertFileDoesNotExist($this->cronDir . '/ran');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /**
     * The pass can run each task some other way: in its own process, under its time limit.
     */
    public function testThePassCanRunEachTaskElsewhere(): void
    {
        $ran = [];
        $ok = $this->runner()->runAll(function (string $name) use (&$ran): bool {
            $ran[] = $name;
            return true;
        });

        $this->assertTrue($ok);
        $this->assertSame(['parsed'], $ran);
        $this->assertFileDoesNotExist($this->cronDir . '/ran', 'The task ran in this process too.');
    }

    public function testAFailureElsewhereFailsThePass(): void
    {
        $this->assertFalse($this->runner()->runAll(fn(string $name): bool => false));
    }

    public function testATaskRunsUnderItsTimeLimit(): void
    {
        $timeout = sys_get_temp_dir() . '/rs-fake-timeout-' . bin2hex(random_bytes(4));
        touch($timeout);
        chmod($timeout, 0755);

        try {
            $this->assertSame(
                "'" . $timeout . "' --kill-after=30 60 '/usr/bin/php' 'cron/run.php' 'hourly'",
                $this->runner()->timeLimitedCommand('hourly', '/usr/bin/php', 'cron/run.php', $timeout)
            );
        } finally {
            unlink($timeout);
        }
    }

    public function testWithoutTimeoutATaskRunsWithoutALimit(): void
    {
        $this->assertSame(
            "'/usr/bin/php' 'cron/run.php' 'hourly'",
            $this->runner()->timeLimitedCommand('hourly', '/usr/bin/php', 'cron/run.php', '/nonexistent/timeout')
        );
    }

    /**
     * What set_time_limit() can't do: stop a task that's waiting, not running.
     */
    public function testATaskThatRunsTooLongIsStopped(): void
    {
        if (!is_executable('/usr/bin/timeout')) {
            $this->markTestSkipped('coreutils timeout is not installed');
        }
        $script = $this->cronDir . '/slow.php';
        file_put_contents($script, '<?php sleep(30);');

        $manifest = self::MANIFEST;
        $manifest['hourly']['timeout'] = 1;
        $runner = new TaskRunner(
            $manifest,
            new TaskContext($this->log(), null, null, null, 0, 2026, '', '', $this->cronDir),
            $this->cronDir
        );

        $started = microtime(true);
        exec($runner->timeLimitedCommand('hourly', PHP_BINARY, $script), $output, $status);

        $this->assertTrue(TaskRunner::timedOut($status), 'Exit status ' . $status);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function testFinishingInTimeIsNotTimingOut(): void
    {
        $this->assertFalse(TaskRunner::timedOut(0));
        $this->assertFalse(TaskRunner::timedOut(1));
    }

    private function log(): Log
    {
        return new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        };
    }

    private function runner(): TaskRunner
    {
        $log = new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        };

        return new TaskRunner(
            self::MANIFEST,
            new TaskContext($log, null, null, null, 0, 2026, '', '', $this->cronDir),
            $this->cronDir
        );
    }
}
