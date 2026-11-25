#!/usr/bin/env php
<?php

/**
 * Start Video Processor EC2 Instance
 *
 * Checks whether new House or Senate videos are available by comparing the
 * latest scraped lists against cached copies on disk. If new videos are found,
 * or the processor has pending work, starts the rs-video-processor EC2 instance.
 *
 * Scrapers:
 * - House (Granicus)
 * - Senate (Granicus)
 * - Senate YouTube (@SenateofVirginia)
 *
 * This script runs on rs-machine (always-on) to trigger the expensive GPU instance
 * only when there's actual work to do.
 *
 * Intended to run via cron regularly during legislative session.
 */

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Jobs\PendingWork;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Scraper\Http\GuzzleHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Http\RateLimitedHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateYouTubeScraper;
use RsMachine\VideoProcessorLauncher;
use RsMachine\VideoProcessorSize;

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

define('MACHINE_CACHE_DIR', __DIR__);

// Each run is a disposable instance launched from this template (rs-video-processor's
// terraform/), which deletes itself when the pipeline is done. The keys need the
// rs-video-processor-launch policy, which Terraform attaches.
define('VIDEO_PROCESSOR_LAUNCH_TEMPLATE', 'rs-video-processor');

// The hand-built instance used before November 2026. Until it is retired it is never started,
// unless VIDEO_PROCESSOR_USE_HAND_BUILT is set to true here to go back to it; but a run of it
// someone started by hand still counts as a processor running. The keys need the inline
// StartVideoProcessor policy to start it.
define('VIDEO_PROCESSOR_INSTANCE_ID', 'i-05a12457e82c9aed5');
define('VIDEO_PROCESSOR_USE_HAND_BUILT', false);

// Instantiate logging
$log = new Log();

if (!class_exists(HouseScraper::class)) {
    $log->put('rs-video-processor classes are not available via Composer autoload.', 6);
    exit(1);
}

/**
 * Load a cached snapshot of scraped records.
 */
function load_scraper_snapshot(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload['records'] ?? $payload;
}

/**
 * Persist scraped records to a snapshot file.
 *
 * @param array<int, array<string, mixed>> $records
 */
function save_scraper_snapshot(string $path, array $records): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $payload = [
        'generated_at' => date(DATE_ATOM),
        'record_count' => count($records),
        'records' => $records,
    ];

    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Build a stable key for a scraped record, mirroring rs-video-processor's pipeline.
 */
function record_key(array $record): string
{
    $id = $record['content_id'] ?? $record['clip_id'] ?? $record['video_id'] ?? uniqid();
    $chamber = $record['chamber'] ?? 'unknown';
    return $chamber . '|' . $id;
}

/**
 * Determine whether any new records exist compared to the cached snapshot.
 *
 * @param array<int, array<string, mixed>> $current
 * @param array<int, array<string, mixed>> $cached
 */
function has_new_records(array $current, array $cached): bool
{
    $current_keys = array_map('record_key', $current);
    $cached_keys = array_map('record_key', $cached);
    $cached_lookup = array_fill_keys($cached_keys, true);

    foreach ($current_keys as $key) {
        if (!isset($cached_lookup[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * Get the current state and type of the EC2 instance
 *
 * @return array{state:?string,type:?string}
 */
function describe_instance(Ec2Client $ec2, string $instanceId): array
{
    try {
        $result = $ec2->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);

        $reservations = $result->get('Reservations');
        if (empty($reservations) || empty($reservations[0]['Instances'])) {
            return ['state' => null, 'type' => null];
        }

        $instance = $reservations[0]['Instances'][0];
        return ['state' => $instance['State']['Name'] ?? null, 'type' => $instance['InstanceType'] ?? null];
    } catch (AwsException $e) {
        return ['state' => null, 'type' => null];
    }
}

/**
 * Change the type of the stopped instance. On failure it is started as it is: a run on the
 * wrong size costs more or goes slower, while no run at all leaves the work waiting.
 */
function resize_instance(Ec2Client $ec2, string $instanceId, string $type, Log $log): bool
{
    try {
        $ec2->modifyInstanceAttribute([
            'InstanceId' => $instanceId,
            'InstanceType' => ['Value' => $type],
        ]);
        return true;
    } catch (AwsException $e) {
        $log->put('Could not make the video processor a ' . $type . '; starting it as it is: ' . $e->getMessage(), 5);
        return false;
    }
}

/**
 * Start the EC2 instance
 */
function start_instance(Ec2Client $ec2, string $instanceId, Log $log): bool
{
    try {
        $ec2->startInstances([
            'InstanceIds' => [$instanceId],
        ]);
        return true;
    } catch (AwsException $e) {
        $log->put('Failed to start video processor instance: ' . $e->getMessage(), 6);
        return false;
    }
}

// Scrape latest House and Senate listings
$http = new RateLimitedHttpClient(
    new GuzzleHttpClient(new Client([
        'timeout' => 60,
        'connect_timeout' => 10,
        'headers' => [
            'User-Agent' => 'rs-machine video processor trigger (+https://richmondsunlight.com/)',
        ],
    ])),
    1.0,
    3,
    5.0
);

$house_scraper = new HouseScraper($http, logger: $log, maxRecords: 50);
$senate_scraper = new SenateScraper($http, logger: $log, maxRecords: 50);
$senate_youtube_scraper = new SenateYouTubeScraper(
    $http,
    YOUTUBE_API_KEY ?? '',
    logger: $log,
    maxRecords: 50
);

try {
    $house_records = $house_scraper->scrape();
    $senate_records = $senate_scraper->scrape();
    $senate_youtube_records = $senate_youtube_scraper->scrape();
} catch (Throwable $e) {
    $log->put('Video scraper failed: ' . $e->getMessage(), 5);
    exit(1);
}

$cache_root = rtrim(MACHINE_CACHE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'video-processor';
$house_snapshot = $cache_root . DIRECTORY_SEPARATOR . 'house-scrape.json';
$senate_snapshot = $cache_root . DIRECTORY_SEPARATOR . 'senate-scrape.json';
$senate_youtube_snapshot = $cache_root . DIRECTORY_SEPARATOR . 'senate-youtube-scrape.json';

$cached_house = load_scraper_snapshot($house_snapshot);
$cached_senate = load_scraper_snapshot($senate_snapshot);
$cached_senate_youtube = load_scraper_snapshot($senate_youtube_snapshot);

$has_new_house = has_new_records($house_records, $cached_house);
$has_new_senate = has_new_records($senate_records, $cached_senate);
$has_new_senate_youtube = has_new_records($senate_youtube_records, $cached_senate_youtube);
$has_new = $has_new_house || $has_new_senate || $has_new_senate_youtube;

// Remember what has been seen only once the instance is known to be up. Saving
// earlier meant a failed start lost the trigger: the video was no longer "new".
$save_snapshots = static function () use (
    $house_snapshot,
    $house_records,
    $senate_snapshot,
    $senate_records,
    $senate_youtube_snapshot,
    $senate_youtube_records
): void {
    save_scraper_snapshot($house_snapshot, $house_records);
    save_scraper_snapshot($senate_snapshot, $senate_records);
    save_scraper_snapshot($senate_youtube_snapshot, $senate_youtube_records);
};

// A backlog is work too. Count what the processor could do right now, using
// the same queue definitions its workers use.
$pending_total = 0;
$pending_counts = [];
$last_activity = null;
try {
    $pdo = (new Database())->connect();
    if ($pdo instanceof PDO && class_exists(PendingWork::class)) {
        $pending = new PendingWork($pdo);
        $pending_counts = $pending->counts();
        $pending_total = array_sum($pending_counts);
        $last_activity = $pending->lastActivity();
    }
} catch (Throwable $e) {
    $log->put('Could not count pending video work: ' . $e->getMessage(), 5);
}

// Once a day, say so if work is waiting and nothing has touched it for days.
if ($pending_total > 0 && (int) date('G') === 12) {
    $idle_days = $last_activity === null ? null : (int) floor((time() - $last_activity) / 86400);
    if ($idle_days === null || $idle_days >= 3) {
        $log->put(sprintf(
            'Video pipeline is not making progress: %d item(s) pending, %s.',
            $pending_total,
            $idle_days === null ? 'and no job has ever been recorded' : "no job touched for {$idle_days} days"
        ), 6);
    }
}

if (!$has_new && $pending_total === 0) {
    $save_snapshots();
    $log->put('No new House or Senate videos and no pending video work.', 1);
    exit(0);
}

$log->put(sprintf(
    'Video work found: new house=%s, new senate=%s, new senate-youtube=%s, pending=%d',
    $has_new_house ? 'yes' : 'no',
    $has_new_senate ? 'yes' : 'no',
    $has_new_senate_youtube ? 'yes' : 'no',
    $pending_total
), 3);

// Initialize EC2 client
try {
    $ec2 = new Ec2Client([
        'region' => 'us-east-1',
        'version' => '2016-11-15',
        'credentials' => [
            'key' => AWS_ACCESS_KEY,
            'secret' => AWS_SECRET_KEY,
        ],
    ]);
} catch (Exception $e) {
    $log->put('Could not initialize EC2 client: ' . $e->getMessage(), 6);
    exit(1);
}

$wanted = VideoProcessorSize::choose($pending_counts, $has_new);

if (!VIDEO_PROCESSOR_USE_HAND_BUILT) {
    $launcher = new VideoProcessorLauncher($ec2, VIDEO_PROCESSOR_LAUNCH_TEMPLATE);
    try {
        $running = $launcher->running();
    } catch (AwsException $e) {
        $log->put('Could not list running video processors: ' . $e->getMessage(), 5);
        exit(1);
    }

    // The hand-built instance isn't tagged, so it is looked up by ID. Once it has been
    // terminated, its state is null or "terminated", and it is ignored.
    ['state' => $hand_built_state] = describe_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID);
    if ($hand_built_state === 'pending' || $hand_built_state === 'running') {
        $running[] = ['id' => VIDEO_PROCESSOR_INSTANCE_ID, 'type' => '', 'state' => $hand_built_state];
    }

    if ($running !== []) {
        $save_snapshots();
        $log->put('A video processor is already ' . $running[0]['state'] . ' (' . $running[0]['id'] . ').', 2);
        exit(0);
    }

    try {
        $launch = $launcher->launch($wanted);
    } catch (AwsException $e) {
        $log->put('Failed to launch a video processor: ' . $e->getMessage(), 6);
        exit(1);
    }

    $save_snapshots();
    $log->put(sprintf(
        'Launched a video processor: %s, %s, %s.%s',
        $launch['id'],
        $wanted,
        $launch['market'],
        $launch['spotRefusal'] === null ? '' : ' Spot was refused (' . $launch['spotRefusal'] . ').'
    ), 4);
    exit(0);
}

// The hand-built instance, if VIDEO_PROCESSOR_USE_HAND_BUILT is set.
['state' => $state, 'type' => $type] = describe_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID);

if ($state === null) {
    $log->put('Could not determine video processor instance state.', 5);
    exit(1);
}

if ($state === 'running' || $state === 'pending') {
    $save_snapshots();
    $log->put('Video processor instance is already ' . $state . '.', 2);
    exit(0);
}

if ($state === 'stopping') {
    // Snapshots are deliberately not saved: the next run must try again.
    $log->put('Video processor instance is stopping; will check again later.', 3);
    exit(0);
}

// Instance is stopped (or in another non-running state). Size it for the work, then start it.
if ($state === 'stopped' && $type !== $wanted && resize_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID, $wanted, $log)) {
    $type = $wanted;
}

if (start_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID, $log)) {
    $save_snapshots();
    $log->put('Started video processor instance (' . ($type ?? 'unknown type') . ').', 4);
} else {
    exit(1);
}
