<?php

use PHPUnit\Framework\TestCase;
use RsMachine\TaskContext;
use RsMachine\TaskRunner;

require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * Running tasks against another session (run.php --session) mustn't change the working files that
 * the current session's runs depend on.
 */
class TaskRunnerIsolationTest extends TestCase
{
    private string $cronDir;

    protected function setUp(): void
    {
        $this->cronDir = sys_get_temp_dir() . '/rs-isolation-test-' . bin2hex(random_bytes(4));
        mkdir($this->cronDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cronDir . '/*'));
        rmdir($this->cronDir);
    }

    public function testStateFilesAreRestoredAfterAnIsolatedRun(): void
    {
        file_put_contents($this->cronDir . '/statuses.json', 'current session');
        chmod($this->cronDir . '/statuses.json', 0644);
        touch($this->cronDir . '/statuses.json', 1700000000);

        $runner = $this->runner(
            '<?php file_put_contents(__DIR__ . "/statuses.json", "other session");'
            . ' file_put_contents(__DIR__ . "/new.json", "other session");',
            ['state' => ['statuses.json', 'new.json']]
        );
        $runner->isolate();

        $this->assertTrue($runner->run('zztest'));
        clearstatcache();
        $this->assertSame('current session', file_get_contents($this->cronDir . '/statuses.json'));
        $this->assertSame(1700000000, filemtime($this->cronDir . '/statuses.json'));
        $this->assertSame(0644, fileperms($this->cronDir . '/statuses.json') & 0777);
        $this->assertFileDoesNotExist($this->cronDir . '/new.json');
    }

    public function testStateFilesAreRestoredWhenATaskThrows(): void
    {
        file_put_contents($this->cronDir . '/statuses.json', 'current session');

        $runner = $this->runner(
            '<?php file_put_contents(__DIR__ . "/statuses.json", "other session");'
            . ' throw new RuntimeException("failed");',
            ['state' => ['statuses.json']]
        );
        $runner->isolate();

        $this->assertFalse($runner->run('zztest'));
        $this->assertSame('current session', file_get_contents($this->cronDir . '/statuses.json'));
    }

    public function testStateFilesAreLeftAloneWithoutIsolation(): void
    {
        file_put_contents($this->cronDir . '/statuses.json', 'current session');

        $runner = $this->runner(
            '<?php file_put_contents(__DIR__ . "/statuses.json", "updated");',
            ['state' => ['statuses.json']]
        );

        $this->assertTrue($runner->run('zztest'));
        $this->assertSame('updated', file_get_contents($this->cronDir . '/statuses.json'));
    }

    /**
     * @param array<string, mixed> $options Extra manifest keys for the task.
     */
    private function runner(string $code, array $options): TaskRunner
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
            ['zztest' => ['schedule' => null, 'needs_database' => false, 'timeout' => 60] + $options],
            $context,
            $this->cronDir
        );
    }
}
