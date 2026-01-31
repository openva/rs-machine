<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

if (!class_exists('NullLogHistory', false)) {
    /**
     * Silent logger for test isolation.
     */
    class NullLogHistory extends Log
    {
        public function put($message, $level = 3)
        {
            return true;
        }

        public function filesystem($message)
        {
            return true;
        }
    }
}

class HistoryComparisonTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheFile = tempnam(sys_get_temp_dir(), 'status-cache-');
        // Ensure the file is empty to simulate first run.
        file_put_contents($this->cacheFile, '');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
        parent::tearDown();
    }

    public function testDetectChangedLegislationStatusesFirstRunReturnsAll(): void
    {
        $import = new Import(new NullLogHistory());
        $current = [
            101 => ['lis_id' => 101, 'number' => 'HB1', 'status' => 'Introduced'],
            102 => ['lis_id' => 102, 'number' => 'SB2', 'status' => 'In Committee'],
        ];

        $changed = $import->detect_changed_legislation_statuses($current, $this->cacheFile);

        sort($changed);
        $this->assertSame([101, 102], $changed, 'First run should treat all statuses as changed.');
        $this->assertFileExists($this->cacheFile);
        $cached = json_decode(file_get_contents($this->cacheFile), true);
        $this->assertCount(2, $cached, 'Cache should persist current statuses.');
    }

    public function testDetectChangedLegislationStatusesOnlyReturnsDifferences(): void
    {
        $import = new Import(new NullLogHistory());

        // Seed cache with initial statuses.
        $initial = [
            ['lis_id' => 101, 'number' => 'HB1', 'status' => 'Introduced'],
            ['lis_id' => 102, 'number' => 'SB2', 'status' => 'In Committee'],
        ];
        file_put_contents($this->cacheFile, json_encode($initial));

        // New fetch changes SB2 status and adds HB3; HB1 unchanged.
        $current = [
            101 => ['lis_id' => 101, 'number' => 'HB1', 'status' => 'Introduced'],
            102 => ['lis_id' => 102, 'number' => 'SB2', 'status' => 'Passed'],
            103 => ['lis_id' => 103, 'number' => 'HB3', 'status' => 'Introduced'],
        ];

        $changed = $import->detect_changed_legislation_statuses($current, $this->cacheFile);
        sort($changed);

        $this->assertSame([102, 103], $changed, 'Only changed or new bills should be flagged.');

        // Cache should be updated to new snapshot.
        $cached = json_decode(file_get_contents($this->cacheFile), true);
        $this->assertCount(3, $cached);
    }
}
