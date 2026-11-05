<?php

use PHPUnit\Framework\TestCase;
use RsMachine\LogRotation;

require_once __DIR__ . '/../../includes/vendor/autoload.php';

/**
 * RsMachine\LogRotation, which archives logs/site.log daily and deletes old archives.
 */
class LogRotationTest extends TestCase
{
    private string $dir;
    private string $log;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rs-log-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->log = $this->dir . '/site.log';
        $this->now = new DateTimeImmutable('2026-11-06 05:45:00');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    public function testTheLogIsArchivedAndStartedAfresh(): void
    {
        $contents = str_repeat("2026-11-05 15:01:03 Task \"bills\" completed in 1234ms\n", 50000);
        file_put_contents($this->log, $contents);

        $archive = LogRotation::rotate($this->log, 30, $this->now);

        $this->assertSame($this->log . '-20261106.gz', $archive);
        $this->assertSame($contents, gzdecode(file_get_contents($archive)));
        $this->assertFileDoesNotExist($this->log);
        $this->assertSame([$archive], glob($this->dir . '/*'), 'Nothing else is left behind.');
    }

    public function testASecondRotationThatDayGetsItsOwnArchive(): void
    {
        file_put_contents($this->log, "first\n");
        $first = LogRotation::rotate($this->log, 30, $this->now);
        file_put_contents($this->log, "second\n");
        $second = LogRotation::rotate($this->log, 30, $this->now->modify('+1 hour'));

        $this->assertNotSame($first, $second);
        $this->assertSame("first\n", gzdecode(file_get_contents($first)));
        $this->assertSame("second\n", gzdecode(file_get_contents($second)));
    }

    public function testAnEmptyOrMissingLogIsNotArchived(): void
    {
        $this->assertNull(LogRotation::rotate($this->log, 30, $this->now));
        touch($this->log);
        $this->assertNull(LogRotation::rotate($this->log, 30, $this->now));
        $this->assertSame([$this->log], glob($this->dir . '/*'));
    }

    public function testOldArchivesAreDeleted(): void
    {
        $old = $this->log . '-20261001.gz';
        $recent = $this->log . '-20261020.gz';
        file_put_contents($old, gzencode('old'));
        file_put_contents($recent, gzencode('recent'));
        touch($old, (new DateTimeImmutable('2026-10-01 05:45'))->getTimestamp());
        touch($recent, (new DateTimeImmutable('2026-10-20 05:45'))->getTimestamp());

        LogRotation::rotate($this->log, 30, $this->now);

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    /**
     * A rotation stopped by its time limit while compressing left the log moved aside.
     */
    public function testAnInterruptedRotationIsFinished(): void
    {
        file_put_contents($this->log . '.rotating', "interrupted\n");
        file_put_contents($this->log . '-20261106.gz.partial', 'half an archive');
        file_put_contents($this->log, "new\n");

        $archive = LogRotation::rotate($this->log, 30, $this->now);

        $this->assertSame("interrupted\n", gzdecode(file_get_contents($archive)));
        $this->assertFileDoesNotExist($this->log . '.rotating');
        $this->assertFileDoesNotExist($this->log . '-20261106.gz.partial');
        $this->assertSame("new\n", file_get_contents($this->log), 'The newer log waits for the next rotation.');
    }
}
