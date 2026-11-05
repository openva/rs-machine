<?php

use PHPUnit\Framework\TestCase;
use RsMachine\HistoryRefresh;
use RsMachine\StateFile;

/**
 * Which bills' histories history.php fetches again, though their status hasn't changed.
 */
class HistoryRefreshTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testNeverFetchedBillsComeFirst(): void
    {
        $lastFetched = [101 => self::NOW - 90_000];

        $this->assertSame([102, 101], HistoryRefresh::due($lastFetched, [101, 102], self::NOW));
    }

    public function testOldestFirst(): void
    {
        $lastFetched = [101 => self::NOW - 90_000, 102 => self::NOW - 200_000, 103 => self::NOW - 100_000];

        $this->assertSame([102, 103, 101], HistoryRefresh::due($lastFetched, [101, 102, 103], self::NOW));
    }

    public function testRecentlyFetchedBillsAreLeftAlone(): void
    {
        $lastFetched = [101 => self::NOW - 3_600, 102 => self::NOW - HistoryRefresh::MAX_AGE];

        $this->assertSame([102], HistoryRefresh::due($lastFetched, [101, 102], self::NOW));
    }

    public function testNoMoreThanTheLimit(): void
    {
        $this->assertSame([1, 2], HistoryRefresh::due([], [1, 2, 3], self::NOW, 2));
    }

    /**
     * A bill not among the candidates (one fetched because its status changed, say) isn't
     * fetched twice.
     */
    public function testOnlyCandidates(): void
    {
        $this->assertSame([2], HistoryRefresh::due([], [2], self::NOW));
    }

    public function testSavesAndLoadsOnlyTheBillsKept(): void
    {
        $path = sys_get_temp_dir() . '/rs-history-fetched-' . bin2hex(random_bytes(4)) . '.json';

        try {
            $this->assertTrue(StateFile::save($path, [7 => 100, 8 => 200, 9 => 300], [8, 9]));
            $this->assertSame([8 => 200, 9 => 300], StateFile::load($path));
        } finally {
            @unlink($path);
        }
    }

    public function testAMissingOrDamagedFileMeansNothingWasFetched(): void
    {
        $path = sys_get_temp_dir() . '/rs-history-fetched-' . bin2hex(random_bytes(4)) . '.json';
        $this->assertSame([], StateFile::load($path));

        file_put_contents($path, '{"8": 2');
        try {
            $this->assertSame([], StateFile::load($path));
        } finally {
            unlink($path);
        }
    }
}
