<?php

use PHPUnit\Framework\TestCase;
use RsMachine\TaskContext;
use RsMachine\TaskRunner;

require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * An "all" pass runs every task on the connection opened at the start. A long task that does not
 * touch the database lets MySQL close it, and every task after that failed in milliseconds with
 * "MySQL server has gone away" (articles, cache, checks and log_prune, October 2026).
 */
class TaskRunnerReconnectTest extends TestCase
{
    private string $cronDir;

    protected function setUp(): void
    {
        $this->cronDir = sys_get_temp_dir() . '/rs-runner-test-' . bin2hex(random_bytes(4));
        mkdir($this->cronDir);
        // The task reports which PDO handle it was given, through the global legacy tasks read.
        file_put_contents($this->cronDir . '/zztest.php', '<?php $GLOBALS["seen"] = $GLOBALS["dbh"];');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cronDir . '/*.php'));
        rmdir($this->cronDir);
        unset($GLOBALS['seen']);
    }

    public function testADeadConnectionIsReplacedBeforeTheTaskRuns(): void
    {
        $fresh = $this->livePdo();
        $reconnects = 0;
        $runner = $this->runner($this->deadPdo(), function () use ($fresh, &$reconnects): array {
            $reconnects++;
            return [null, $fresh];
        });

        $this->assertTrue($runner->run('zztest'));
        $this->assertSame($fresh, $GLOBALS['seen']);
        $this->assertSame(1, $reconnects);

        $this->assertTrue($runner->run('zztest'), 'The next task keeps the new connection.');
        $this->assertSame($fresh, $GLOBALS['seen']);
        $this->assertSame(1, $reconnects, 'A live connection is not replaced.');
    }

    public function testALiveConnectionIsKept(): void
    {
        $live = $this->livePdo();
        $runner = $this->runner($live, function (): array {
            $this->fail('A live connection must not be replaced.');
        });

        $this->assertTrue($runner->run('zztest'));
        $this->assertSame($live, $GLOBALS['seen']);
    }

    /*
     * Stand-ins for PDO connections, so the test needs no PDO driver (the Docker container's PHP
     * has none but MySQL's). The runner checks a connection only by running "SELECT 1" on it.
     */

    /** A connection that answers queries. */
    private function livePdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($this->createStub(PDOStatement::class));
        return $pdo;
    }

    /** A connection that has been closed by the server, as PDO reports it. */
    private function deadPdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willThrowException(
            new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')
        );
        return $pdo;
    }

    private function runner(PDO $dbh, Closure $reconnect): TaskRunner
    {
        $log = new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        };

        return new TaskRunner(
            ['zztest' => ['schedule' => null, 'needs_database' => false, 'timeout' => 60]],
            new TaskContext($log, null, $dbh, null, 0, 2026, '', '', $this->cronDir),
            $this->cronDir,
            $reconnect
        );
    }
}
