<?php

/*
 * Use a DOM parser for the screen-scraper.
 */
use Sunra\PhpSimple\HtmlDomParser;

function get_legislator_data($chamber, $lis_id)
{
	if ( empty($chamber) || empty($lis_id) )
	{
		return FALSE;
	}

	if ($chamber == 'house')
	{
		$url = 'https://virginiageneralassembly.gov/house/members/members.php?ses=' . SESSION_YEAR . '&id='
			. $lis_id;
		$html = file_get_contents($url);
		$dom = HtmlDomParser::str_get_html($html);
		// REQUIRED FIELDS
		//name_formal
		//name
		//name_formatted (incl. placename)
		//shortname
		//lis_shortname
		//chamber
		//district_id
		//date_started
		//party
		//race
		//sex
	}

}

/*
 * Retrieve a list of all active legislators' names and IDs. Though that's not *quite* right.
 * Within a couple of weeks of the election, the legislature's website pretends that the departing
 * legislators are already out office. New legislators are listed, departing ones are gone. To
 * avoid two solid months of errors, instead we get a list of legislators with no end date.
 */
$sql = 'SELECT name, chamber, lis_id
		FROM representatives
		WHERE date_ended IS NULL
		ORDER BY chamber ASC';
$stmt = $dbh->prepare($sql);
$stmt->execute();
$known_legislators = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($known_legislators as &$known_legislator)
{
	if ( ($known_legislator->lis_id[0] != 'S') && ($known_legislator->lis_id[0] != 'H') )
	{
		if ($known_legislator->chamber == 'senate')
		{
			$known_legislator->lis_id = 'S' . $known_legislator->lis_id;
		}
		elseif ($known_legislator->chamber == 'house')
		{
			$known_legislator->lis_id = 'H' . str_pad($known_legislator->lis_id, 4, '0', STR_PAD_LEFT);
		}
	}
}

$log->put('Loaded ' . count($known_legislators) . ' legislators from local database.', 1);
if (count($known_legislators) > 140)
{
	$log->put('There are ' . count($known_legislators) . ' legislators in the database—too many.', 5);
}

/*
 * Get senators. Their Senate ID (e.g., "S100") is the key, their name is the value.
 */
$html = get_content('https://apps.senate.virginia.gov/Senator/index.php');
preg_match_all('/id=S([0-9]{2,3})(?:.*)<u>(.+)<\/u>/', $html, $senators);
$tmp = array();
$i=0;
foreach ($senators[1] as $senator)
{
	$tmp['S'.$senator] = trim($senators[2][$i]);
	$i++;
}
$senators = $tmp;
unset($tmp);

$log->put('Retrieved ' . count($senators) . ' senators from senate.virginia.gov.', 1);

/*
 * Get delegates. Their House ID (e.g., "H0200") is the key, their name is the value.
 */
$html = get_content('https://virginiageneralassembly.gov/house/members/members.php?ses=' . SESSION_YEAR);
preg_match_all('/id=\'member\[H([0-9]+)\]\'><td width="190px"><a class="bioOpener" href="#">(.*?)<\/a>/m', $html, $delegates);
$tmp = array();
$i=0;
foreach ($delegates[1] as $delegate)
{
	$tmp['H'.$delegate] = trim($delegates[2][$i]);
	$i++;
}
$delegates = $tmp;
unset($tmp);

$log->put('Retrieved ' . count($delegates) . ' delegates from virginiageneralassembly.gov.', 1);

/*
 * First see if we have records of any representatives that are not currently in office.
 */
foreach ($known_legislators as $known_legislator)
{

	$id = $known_legislator->lis_id;

	/*
	 * Check senators.
	 */
	if ($known_legislator->chamber == 'senate')
	{
		if (!isset($senators[$id]))
		{
			$log->put('Error: Sen. ' . $known_legislator->name . ' is no longer in office, but is still listed in the database.', 5);
		}
	}

	/*
	 * Check delegates.
	 */
	elseif ($known_legislator->chamber == 'house')
	{
		if (!isset($delegates[$id]))
		{
			$log->put('Error: Del. ' . $known_legislator->name . ' is no longer in office, but is still listed in the database.', 5);
		}
	}

}

/*
 * Second, see there are any delegates or senators who are not in our records.
 */
foreach ($senators as $lis_id => $name)
{

	$match = FALSE;

	foreach ($known_legislators as $known_legislator)
	{

		if ($known_legislator->lis_id == $lis_id)
		{
			$match = TRUE;
			continue(2);
		}

	}

	if ($match == FALSE && $name != 'Vacant')
	{
		$log->put('Senator missing from the database: ' . $name . ' (http://apps.senate.virginia.gov/Senator/memberpage.php?id=' . $lis_id . ')', 6);
	}

}

foreach ($delegates as $lis_id => $name)
{

	$match = FALSE;

	foreach ($known_legislators as $known_legislator)
	{

		if ($known_legislator->lis_id == $lis_id)
		{
			$match = TRUE;
			continue(2);
		}

	}

	if ($match == FALSE && $name != 'Vacant')
	{
		$log->put('Delegate missing from the database: ' . $name . ' (http://virginiageneralassembly.gov/house/members/members.php?id='. $lis_id . ')', 6);
	}

}
