<?php

use PHPUnit\Framework\TestCase;
use RsMachine\TaskContext;
use RsMachine\TaskRunner;

require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * What the runner does when it can't fetch a task's files from LIS. LIS publishes each session's
 * files only once it has data for them, so a new session's files are missing for a while, and
 * the copies in cron/ are the previous session's.
 */
class TaskRunnerPrefetchTest extends TestCase
{
    private string $cronDir;

    /** @var string[] What the runner logged. */
    private array $logged = [];

    /** @var int[] The level of each message in $logged. */
    private array $levels = [];

    protected function setUp(): void
    {
        $this->cronDir = sys_get_temp_dir() . '/rs-prefetch-test-' . bin2hex(random_bytes(4));
        mkdir($this->cronDir);

        /*
         * A stand-in for fetch_csv.sh, which exits as it does: 4 for a file that LIS doesn't
         * have ("MISSING.CSV"), 1 for any other failure ("BROKEN.CSV").
         */
        file_put_contents($this->cronDir . '/fetch_csv.sh', <<<'SH'
            case "$1" in
                MISSING*) exit 4 ;;
                BROKEN*) exit 1 ;;
                *) echo "this session" > "$2" ;;
            esac
            SH);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cronDir . '/*'));
        rmdir($this->cronDir);
    }

    public function testATaskIsSkippedWhenLisHasNoneOfItsFiles(): void
    {
        file_put_contents($this->cronDir . '/missing.csv', 'last session');

        $runner = $this->runner(['MISSING.CSV' => 'missing.csv']);

        $this->assertTrue($runner->run('zztest'));
        $this->assertFileDoesNotExist($this->cronDir . '/ran');
        $this->assertFileDoesNotExist($this->cronDir . '/missing.csv');
        $this->assertStringContainsString('skipped', $runner->summary());
        $this->assertContains(
            'Task "zztest" skipped after 0ms: LIS has no MISSING.CSV for this session',
            preg_replace('/after \d+ms/', 'after 0ms', $this->logged)
        );
    }

    /**
     * The task gets the files that LIS has, and none of the previous session's.
     */
    public function testATaskRunsWithTheFilesThatLisHas(): void
    {
        file_put_contents($this->cronDir . '/missing.csv', 'last session');
        file_put_contents($this->cronDir . '/present.csv', 'last session');

        $runner = $this->runner(['PRESENT.CSV' => 'present.csv', 'MISSING.CSV' => 'missing.csv']);

        $this->assertTrue($runner->run('zztest'));
        $this->assertFileExists($this->cronDir . '/ran');
        $this->assertFileDoesNotExist($this->cronDir . '/missing.csv');
        $this->assertSame("this session\n", file_get_contents($this->cronDir . '/present.csv'));
    }

    /**
     * Any other failure, like LIS being down, leaves the last copy, for the task to use.
     */
    public function testAFailedFetchKeepsTheLastCopy(): void
    {
        file_put_contents($this->cronDir . '/broken.csv', 'last fetched');

        $runner = $this->runner(['BROKEN.CSV' => 'broken.csv']);

        $this->assertTrue($runner->run('zztest'));
        $this->assertFileExists($this->cronDir . '/ran');
        $this->assertSame('last fetched', file_get_contents($this->cronDir . '/broken.csv'));
    }

    /**
     * Run against another session (run.php --session), a file that LIS doesn't have skips the
     * task too, and the current session's copy is put back afterward.
     */
    public function testAnIsolatedRunIsSkippedAndKeepsTheCurrentSessionsCopy(): void
    {
        file_put_contents($this->cronDir . '/missing.csv', 'current session');

        $runner = $this->runner(['MISSING.CSV' => 'missing.csv']);
        $runner->isolate();

        $this->assertTrue($runner->run('zztest'));
        $this->assertFileDoesNotExist($this->cronDir . '/ran');
        $this->assertSame('current session', file_get_contents($this->cronDir . '/missing.csv'));
    }

    /**
     * Before a session starts, LIS hasn't published some of its files yet, so that's routine.
     */
    public function testAMissingFileIsRoutineBeforeTheSession(): void
    {
        $this->runner(['MISSING.CSV' => 'missing.csv'], before_session: true)->run('zztest');

        $this->assertSame(2, $this->levelOf('LIS has no MISSING.CSV'));
    }

    /**
     * During the session (or out of season, when the last session's files are all published), a
     * missing file is an error.
     */
    public function testAMissingFileIsAnErrorOtherwise(): void
    {
        $this->runner(['MISSING.CSV' => 'missing.csv'])->run('zztest');

        $this->assertSame(6, $this->levelOf('LIS has no MISSING.CSV'));
    }

    public function testAnIsolatedRunStillFailsWhenAFetchFails(): void
    {
        $runner = $this->runner(['BROKEN.CSV' => 'broken.csv']);
        $runner->isolate();

        $this->assertFalse($runner->run('zztest'));
        $this->assertFileDoesNotExist($this->cronDir . '/ran');
    }

    /**
     * @param array<string, string> $prefetch The task's files, as the manifest gives them.
     */
    private function runner(array $prefetch, bool $before_session = false): TaskRunner
    {
        file_put_contents($this->cronDir . '/zztest.php', '<?php touch(__DIR__ . "/ran");');

        $log = new class ($this->logged, $this->levels) extends Log {
            public function __construct(private array &$logged, private array &$levels)
            {
            }

            public function put($message, $level = 3)
            {
                $this->logged[] = $message;
                $this->levels[] = $level;
                return true;
            }
        };

        $context = new TaskContext($log, null, null, null, 0, 2027, '271', '', $this->cronDir);

        return new TaskRunner(
            ['zztest' => [
                'schedule' => null,
                'needs_database' => false,
                'timeout' => 60,
                'prefetch' => $prefetch,
            ]],
            $context,
            $this->cronDir,
            null,
            $before_session
        );
    }

    /** The level that the first message starting with $prefix was logged at. */
    private function levelOf(string $prefix): ?int
    {
        foreach ($this->logged as $i => $message) {
            if (str_starts_with($message, $prefix)) {
                return $this->levels[$i];
            }
        }

        return null;
    }
}
