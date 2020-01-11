<?php

include '../../includes/class.Import.php';

/*
 * A sample committee members file
 */
$committee_members_csv = '"CMB_COMNO","CMB_MBRNO"
"H01","H0076"
"H01","H0296"
"H02","H0172"
"S08","S0017"';

/*
 * Sample list of legislators, corresponding to the CSV
 */
$legislators_json = '[
    {"id":"74","lis_id":"76","chamber":"house"},
    {"id":"87","lis_id":"172","chamber":"house"},
    {"id":"276","lis_id":"17","chamber":"senate"},
    {"id":"434","lis_id":"296","chamber":"house"}
]';

$legislators = json_decode($legislators_json);
foreach ($legislators as &$legislator)
{
    $legislator = (array) $legislator;
}

/*
 * Sample list of committees, corresponding to the CSV
 */
$committees_json = '[
    {"id":"1","lis_id":"1","chamber":"house"},
    {"id":"2","lis_id":"2","chamber":"house"},
    {"id":"22","lis_id":"8","chamber":"senate"}
]';

$committees = (array) json_decode($committees_json);
foreach ($committees as &$committee)
{
    $committee = (array) $committee;
}

$members = Import::committee_members_csv_parse($committee_members_csv, $committees, $legislators);

if ($members[0]['legislator_id'] != 74)
{
    echo 'Error: Legislator ID was ' . $members[0]['legislator_id'] . ', expected "74"';
    $error = TRUE;
}

if ($members[0]['committee_id'] != 1)
{
    echo 'Error: Committee ID was ' . $members[0]['committee_id'] . ', expected "1"';
    $error = TRUE;
}

if ($members[3]['legislator_id'] != 276)
{
    echo 'Error: Legislator ID was ' . $members[0]['legislator_id'] . ', expected "276"';
    $error = TRUE;
}

if ($members[3]['committee_id'] != 22)
{
    echo 'Error: Committee ID was ' . $members[0]['committee_id'] . ', expected "22"';
    $error = TRUE;
}

if ($error == TRUE)
{
    return FALSE;
}

echo 'All tests passed';
