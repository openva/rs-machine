<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * Which EC2 instance type the video processor should start as, judged by the work waiting.
 *
 * Downloading, screenshotting and OCR (bills, speakers) keep four processors busy. Uploading to
 * the Internet Archive and sending audio to OpenAI keep them waiting on someone else: CloudWatch
 * showed the c7a.xlarge at 0.5% CPU for 10 to 12 hours a day in November 2026, doing nothing
 * but archive uploads, at $0.205 an hour. The small type costs $0.0376.
 *
 * The type can be changed only while the instance is stopped, so a run started small stays
 * small even if new videos arrive during it: they are processed slowly, not lost.
 */
final class VideoProcessorSize
{
    public const LARGE = 'c7a.xlarge';

    /**
     * 4 GiB, not t3a.small's 2, until the CloudWatch agent shows how much memory the archive
     * and transcript stages use.
     */
    public const SMALL = 't3a.medium';

    /** The stages that need the large instance (JobLedger::STAGE_* in rs-video-processor). */
    private const CPU_STAGES = ['download', 'screenshots', 'bills', 'speakers'];

    /**
     * @param array<string,int> $pendingCounts PendingWork::counts(): stage name => jobs waiting
     * @param bool $newVideos whether the scrapers found videos the processor hasn't imported
     */
    public static function choose(array $pendingCounts, bool $newVideos): string
    {
        if ($newVideos) {
            return self::LARGE;
        }

        foreach (self::CPU_STAGES as $stage) {
            // A stage missing from the counts is unknown, so it might have work.
            if (!isset($pendingCounts[$stage]) || $pendingCounts[$stage] > 0) {
                return self::LARGE;
            }
        }

        return self::SMALL;
    }
}
