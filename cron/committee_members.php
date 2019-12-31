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
    $members = $import->committee_members_csv_parse($csv, $committees, $legislators);
}

/*
 * Compare this list to the existing member list
 */
$committee = new Committee();
$committee->members();
$legislators = $committee->members;

// Store the new member list
