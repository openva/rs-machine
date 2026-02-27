#!/usr/bin/env php
<?php

/**
 * Fetch Legislator Photos
 *
 * Reads legislator shortnames from stdin (one per line) and downloads a photo
 * for each one. Photos are saved to the photos/ directory and must be manually
 * committed to the richmondsunlight.com Git repo.
 *
 * Usage:
 *   php utilities/fetch_photos.php
 *   (then paste shortnames, one per line, and press Ctrl+D)
 *
 * Or from a file:
 *   php utilities/fetch_photos.php < missing.txt
 */

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/class.Database.php');
include_once(__DIR__ . '/../includes/class.Log.php');
include_once(__DIR__ . '/../includes/class.Import.php');

$log = new Log();

chdir(getenv('HOME'));

$database = new Database();
$db = $database->connect();

if (!$db instanceof PDO) {
    $log->put('fetch_photos: database connection failed.', 6);
    exit(1);
}

$import = new Import($log);

$shortnames = [];
while (($line = fgets(STDIN)) !== false) {
    $shortname = strtolower(trim($line));
    if ($shortname !== '') {
        $shortnames[] = $shortname;
    }
}

if (empty($shortnames)) {
    $log->put('fetch_photos: no shortnames provided.', 4);
    exit(0);
}

$log->put('fetch_photos: processing ' . count($shortnames) . ' shortname(s).', 3);

$stmt = $db->prepare(
    'SELECT
        p.shortname,
        p.name,
        t.chamber,
        t.lis_id,
        d.number AS district_number
    FROM people p
    INNER JOIN terms t ON t.person_id = p.id
    LEFT JOIN districts d ON d.id = t.district_id
    WHERE p.shortname = :shortname
        AND t.date_ended IS NULL
    ORDER BY t.date_started DESC
    LIMIT 1'
);

foreach ($shortnames as $shortname) {
    $stmt->execute([':shortname' => $shortname]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $log->put('fetch_photos: no active term found for shortname "' . $shortname . '".', 5);
        continue;
    }

    $chamber = $row['chamber'];
    $lis_id = $row['lis_id'];

    if ($chamber === 'house') {
        $member_number = 'H' . str_pad($lis_id, 4, '0', STR_PAD_LEFT);
        $photo_url = 'https://memdata.virginiageneralassembly.gov/images/display_image/' . $member_number;
    } elseif ($chamber === 'senate') {
        $lastname = trim(explode(',', $row['name'], 2)[0]);
        $district_number = $row['district_number'] ?? '';
        if ($lastname === '' || $district_number === '') {
            $log->put('fetch_photos: cannot build Senate photo URL for "' . $shortname . '" — missing last name or district number.', 5);
            continue;
        }
        $photo_url = 'https://apps.senate.virginia.gov/Senator/images/member_photos/' . $lastname . $district_number;
    } else {
        $log->put('fetch_photos: unknown chamber "' . $chamber . '" for "' . $shortname . '".', 5);
        continue;
    }

    $result = $import->fetch_photo($photo_url, $shortname);
    if ($result === false) {
        $log->put('fetch_photos: failed to fetch photo for "' . $shortname . '" from ' . $photo_url, 5);
    } else {
        $log->put('fetch_photos: saved photo for "' . $shortname . '" to ' . $result, 4);
    }
}
