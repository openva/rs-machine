#!/usr/bin/env php
<?php

/**
 * Start Video Processor EC2 Instance
 *
 * Checks whether new House or Senate videos are available by comparing the
 * latest scraped lists against cached copies on disk. If new videos are found,
 * starts the rs-video-processor EC2 instance.
 *
 * This script runs on rs-machine (always-on) to trigger the expensive GPU instance
 * only when there's actual work to do.
 *
 * Intended to run via cron regularly during legislative session.
 */

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Scraper\Http\GuzzleHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Http\RateLimitedHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

define('MACHINE_CACHE_DIR', __DIR__);

// Video processor EC2 instance ID
// Note that this will not work unless a) the instance ID is correct and b) the AWS keys have
// permission to start it. The permissions are scoped down to the specific instance ID.
define('VIDEO_PROCESSOR_INSTANCE_ID', 'i-05a12457e82c9aed5');

// Instantiate logging
$log = new Log();

// Locate rs-video-processor repo (sibling of rs-machine)
$video_processor_root = realpath(__DIR__ . '/../includes/vendor/openva/rs-video-processor');
if ($video_processor_root === false) {
    $log->put('Could not locate rs-video-processor repository.', 6);
    exit(1);
}

$video_autoload = $video_processor_root . '/includes/vendor/autoload.php';
if (!file_exists($video_autoload)) {
    $log->put('rs-video-processor dependencies not found at ' . $video_autoload, 6);
    exit(1);
}

require_once $video_autoload;

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
 * Get the current state of the EC2 instance
 */
function get_instance_state(Ec2Client $ec2, string $instanceId): ?string
{
    try {
        $result = $ec2->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);

        $reservations = $result->get('Reservations');
        if (empty($reservations) || empty($reservations[0]['Instances'])) {
            return null;
        }

        return $reservations[0]['Instances'][0]['State']['Name'] ?? null;
    } catch (AwsException $e) {
        return null;
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

try {
    $house_records = $house_scraper->scrape();
    $senate_records = $senate_scraper->scrape();
} catch (Throwable $e) {
    $log->put('Video scraper failed: ' . $e->getMessage(), 5);
    exit(1);
}

$cache_root = rtrim(MACHINE_CACHE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'video-processor';
$house_snapshot = $cache_root . DIRECTORY_SEPARATOR . 'house-scrape.json';
$senate_snapshot = $cache_root . DIRECTORY_SEPARATOR . 'senate-scrape.json';

$cached_house = load_scraper_snapshot($house_snapshot);
$cached_senate = load_scraper_snapshot($senate_snapshot);

$has_new_house = has_new_records($house_records, $cached_house);
$has_new_senate = has_new_records($senate_records, $cached_senate);

save_scraper_snapshot($house_snapshot, $house_records);
save_scraper_snapshot($senate_snapshot, $senate_records);

if (!$has_new_house && !$has_new_senate) {
    $log->put('No new House or Senate videos detected.', 1);
    exit(0);
}

$log->put(sprintf(
    'New videos detected: house=%s, senate=%s',
    $has_new_house ? 'yes' : 'no',
    $has_new_senate ? 'yes' : 'no'
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

// Check instance state
$state = get_instance_state($ec2, VIDEO_PROCESSOR_INSTANCE_ID);

if ($state === null) {
    $log->put('Could not determine video processor instance state.', 5);
    exit(1);
}

if ($state === 'running') {
    $log->put('Video processor instance already running.', 2);
    exit(0);
}

if ($state === 'pending') {
    $log->put('Video processor instance is starting.', 2);
    exit(0);
}

if ($state === 'stopping') {
    $log->put('Video processor instance is stopping; will check again later.', 3);
    exit(0);
}

// Instance is stopped (or in another non-running state) - start it
if (start_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID, $log)) {
    $log->put('Started video processor instance (new videos detected).', 4);
} else {
    exit(1);
}
