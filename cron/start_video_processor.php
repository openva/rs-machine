#!/usr/bin/env php
<?php

/**
 * Start Video Processor EC2 Instance
 *
 * Checks if there are videos in the database that need processing (downloading,
 * screenshots, transcripts, bill detection, speaker detection, or archival).
 * If work is pending, starts the rs-video-processor EC2 instance.
 *
 * This script runs on rs-machine (always-on) to trigger the expensive GPU instance
 * only when there's actual work to do.
 *
 * Intended to run via cron every 15-30 minutes during legislative session.
 */

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

// Video processor EC2 instance ID
define('VIDEO_PROCESSOR_INSTANCE_ID', 'i-076d0d5ee323c4e83');

// Instantiate logging
$log = new Log();

// Connect to database
$database = new Database();
$db = $database->connect();

if (!$db) {
    $log->put('Could not connect to database for video processor check.', 6);
    exit(1);
}

/**
 * Check for videos needing download (have video_index_cache but path not on S3)
 */
function count_videos_needing_download(PDO $db): int
{
    $sql = "SELECT COUNT(*) as count FROM files
            WHERE video_index_cache IS NOT NULL
            AND (path IS NULL OR path = '' OR path NOT LIKE 'https://video.richmondsunlight.com/%')";
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
}

/**
 * Check for videos needing screenshots (have S3 path but no capture_directory)
 */
function count_videos_needing_screenshots(PDO $db): int
{
    $sql = "SELECT COUNT(*) as count FROM files
            WHERE path LIKE 'https://video.richmondsunlight.com/%'
            AND (capture_directory IS NULL OR capture_directory = '')";
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
}

/**
 * Check for videos needing transcripts (have screenshots but no transcript entries)
 */
function count_videos_needing_transcripts(PDO $db): int
{
    $sql = "SELECT COUNT(*) as count FROM files f
            WHERE f.capture_directory IS NOT NULL
            AND f.capture_directory != ''
            AND NOT EXISTS (
                SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id
            )";
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
}

/**
 * Check for videos needing bill detection (have screenshots but no bill index entries)
 */
function count_videos_needing_bill_detection(PDO $db): int
{
    $sql = "SELECT COUNT(*) as count FROM files f
            WHERE f.capture_directory IS NOT NULL
            AND f.capture_directory != ''
            AND NOT EXISTS (
                SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'bill'
            )";
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
}

/**
 * Check for videos needing speaker detection (have screenshots but no speaker index entries)
 */
function count_videos_needing_speaker_detection(PDO $db): int
{
    $sql = "SELECT COUNT(*) as count FROM files f
            WHERE f.capture_directory IS NOT NULL
            AND f.capture_directory != ''
            AND NOT EXISTS (
                SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'legislator'
            )";
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
}

/**
 * Check for videos needing Internet Archive upload (processed but not archived)
 */
function count_videos_needing_archive(PDO $db): int
{
    $sql = "SELECT COUNT(*) as count FROM files f
            WHERE f.capture_directory IS NOT NULL
            AND f.capture_directory != ''
            AND f.path LIKE 'https://video.richmondsunlight.com/%'
            AND f.path NOT LIKE 'https://archive.org/%'";
    $stmt = $db->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
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

// Count pending work
$pending = [
    'download' => count_videos_needing_download($db),
    'screenshots' => count_videos_needing_screenshots($db),
    'transcripts' => count_videos_needing_transcripts($db),
    'bill_detection' => count_videos_needing_bill_detection($db),
    'speaker_detection' => count_videos_needing_speaker_detection($db),
    'archive' => count_videos_needing_archive($db),
];

$total_pending = array_sum($pending);

// Log current status
if ($total_pending === 0) {
    $log->put('No videos pending processing.', 1);
    exit(0);
}

$log->put(sprintf(
    'Videos pending: %d download, %d screenshots, %d transcripts, %d bill detection, %d speaker detection, %d archive',
    $pending['download'],
    $pending['screenshots'],
    $pending['transcripts'],
    $pending['bill_detection'],
    $pending['speaker_detection'],
    $pending['archive']
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
    $log->put('Video processor instance is starting up.', 2);
    exit(0);
}

if ($state === 'stopping') {
    $log->put('Video processor instance is stopping; will check again later.', 3);
    exit(0);
}

// Instance is stopped (or in another non-running state) - start it
if (start_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID, $log)) {
    $log->put(sprintf(
        'Started video processor instance (%d videos pending processing).',
        $total_pending
    ), 5);
} else {
    exit(1);
}
