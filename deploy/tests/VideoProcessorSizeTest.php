<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RsMachine\VideoProcessorSize;

require_once __DIR__ . '/../../includes/vendor/autoload.php';

/**
 * RsMachine\VideoProcessorSize, which picks the video processor's instance type
 * from the work waiting for it.
 */
class VideoProcessorSizeTest extends TestCase
{
    private const NOTHING = [
        'download' => 0,
        'screenshots' => 0,
        'transcript' => 0,
        'bills' => 0,
        'speakers' => 0,
        'archive' => 0,
        'archive_captions' => 0,
    ];

    public function testUploadsAndTranscriptsAloneGetTheSmallInstance(): void
    {
        $counts = ['archive' => 40, 'transcript' => 12, 'archive_captions' => 3] + self::NOTHING;

        $this->assertSame(VideoProcessorSize::SMALL, VideoProcessorSize::choose($counts, false));
    }

    #[DataProvider('cpuStages')]
    public function testAnyProcessorHeavyStageGetsTheLargeInstance(string $stage): void
    {
        $counts = [$stage => 1, 'archive' => 40] + self::NOTHING;

        $this->assertSame(VideoProcessorSize::LARGE, VideoProcessorSize::choose($counts, false));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function cpuStages(): array
    {
        return [
            'download' => ['download'],
            'screenshots' => ['screenshots'],
            'bills' => ['bills'],
            'speakers' => ['speakers'],
        ];
    }

    public function testNewVideosGetTheLargeInstance(): void
    {
        // They will be downloaded and screenshotted once the pipeline imports them.
        $this->assertSame(VideoProcessorSize::LARGE, VideoProcessorSize::choose(['archive' => 40] + self::NOTHING, true));
    }

    public function testUnknownCountsGetTheLargeInstance(): void
    {
        // The counts could not be read; a slow run is worse than a dear one.
        $this->assertSame(VideoProcessorSize::LARGE, VideoProcessorSize::choose([], true));
        $this->assertSame(VideoProcessorSize::LARGE, VideoProcessorSize::choose(['archive' => 4], false));
    }
}
