<?php

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

/*
 * Instantiate the logging class
 */
$log = new Log();

$import = new Import($log);

die('Read through all code before running this');

/*
 * Generate a list of committees as a lookup table.
 */
$committees = $import->create_committee_list();

/*
 * Generate a list of legislators as a lookup table.
 */
$legislators = $import->create_legislator_list();

/*
 * Ingest the locally stored CSV file that has committee members.
 */
$csv = trim(file_get_contents('committee_members.csv'));

/*
 * If the CSV is at least 5,000 characters long, it's probably valid.
 */
if (strlen($csv) > 5000) {
    $new_members = $import->committee_members_csv_parse($csv, $committees, $legislators);
}

/*
 * Make sure we have a plausible number of committee memberships.
 */
if (count($new_members) < 300) {
    die('Error: Only ' . count($new_members) . ' entries were found in the CSV.');
}

/*
 * Compare this list to the existing member list
 */
$committee = new Committee();
$committee->members();
$existing_members = $committee->members;

$database = new Database();
$db = $database->connect_mysqli();

/*
 * Store the new member list
 */
foreach ($new_members as $new_member) {
    $sql = 'INSERT INTO committee_members
            SET committee_id = ' . $new_member['committee_id'] . ',
            representative_id = ' . $new_member['legislator_id'] . ',
            date_started = "2022-01-12",
            date_created=now()';
    //mysqli_query($db, $sql);
}
