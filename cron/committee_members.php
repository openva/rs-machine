<?php

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

$import = new Import();

/*
 * Generate a list of committees as a lookup table.
 */
$committees = $import->create_committee_list();

/*
 * Generate a list of legislators as a lookup table.
 */
$legislators = $import->create_legislator_list();

if ($csv = $import->committee_members_csv_fetch())
{
    $new_members = $import->committee_members_csv_parse($csv, $committees, $legislators);
}

/*
 * Compare this list to the existing member list
 */
$committee = new Committee();
$committee->members();
$existing_members = $committee->members;

exit('Make real, real sure you want to run this.');

$database = new Database;
$db = $database->connect_mysqli();

// Store the new member list
foreach ($new_members as $new_member)
{

    $sql = 'INSERT INTO committee_members
            SET committee_id = ' . $new_member['committee_id'] . ',
            representative_id = ' . $new_member['legislator_id'] . ',
            date_started = "2020-01-08",
            date_created=now()';
    mysqli_query($db, $sql);

}
