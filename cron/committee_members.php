<?php

require('includes/class.Import.php');

/*
 * Generate a list of committees as a lookup table.
 */
$committees = create_committee_list();

/*
 * Generate a list of legislators as a lookup table.
 */
$legislators = create_legislator_list();

if ($csv = committee_members_csv_fetch())
{
    committee_members_csv_parse($csv, $committees, $legislators);
}
