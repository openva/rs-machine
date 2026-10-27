<?php

use PHPUnit\Framework\TestCase;
use RsMachine\TaskContext;
use RsMachine\TaskRunner;

require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * How the runner interprets what a legacy (procedural) task file returns.
 */
class TaskRunnerLegacyResultTest extends TestCase
{
    private string $cronDir;

    protected function setUp(): void
    {
        $this->cronDir = sys_get_temp_dir() . '/rs-runner-test-' . bin2hex(random_bytes(4));
        mkdir($this->cronDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cronDir . '/*.php'));
        rmdir($this->cronDir);
    }

    /**
     * A task with nothing to do (e.g. votes, out of session) must not be reported as a failure.
     */
    public function testLegacyFileReturningSkippedIsNotAFailure(): void
    {
        $runner = $this->runnerWithTask('<?php return \RsMachine\TaskResult::skipped("nothing to do");');

        $this->assertTrue($runner->run('zztest'));
        $this->assertStringContainsString('skipped', $runner->summary());
    }

    public function testLegacyFileReturningFalseIsAFailure(): void
    {
        $runner = $this->runnerWithTask('<?php return false;');

        $this->assertFalse($runner->run('zztest'));
    }

    public function testLegacyFileFallingThroughIsCompleted(): void
    {
        $runner = $this->runnerWithTask('<?php $x = 1;');

        $this->assertTrue($runner->run('zztest'));
    }

    private function runnerWithTask(string $code): TaskRunner
    {
        file_put_contents($this->cronDir . '/zztest.php', $code);

        $log = new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        };

        $context = new TaskContext($log, null, null, null, 0, 2026, '', '', $this->cronDir);

        return new TaskRunner(
            ['zztest' => ['schedule' => null, 'needs_database' => false, 'timeout' => 60]],
            $context,
            $this->cronDir
        );
    }
}
