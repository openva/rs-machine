<?php

/*
 * Use a DOM parser for the screen-scraper.
 */
use Sunra\PhpSimple\HtmlDomParser;

function get_legislator_data($chamber, $lis_id)
{

	if ( empty($chamber) || empty($lis_id) )
	{
		return false;
	}

	if ($chamber == 'house')
	{

		/*
		 * Format the LIS ID to use the prescribed URL format.
		 */
		$lis_id = 'H' . str_pad($lis_id, 4, '0', STR_PAD_LEFT);

		/*
		 * Fetch the HTML and save parse the DOM.
		 */
		$url = 'https://virginiageneralassembly.gov/house/members/members.php?id=' . $lis_id;
		$html = file_get_contents($url);
		if ($html === false)
		{
			return false;
		}
		$dom = HtmlDomParser::str_get_html($html);
		if ($dom === false)
		{
			return false;
		}

		/*
		 * The array we'll store legislator data in.
		 */
		$legislator = array();

		/*
		 * Get delegate name.
		 */
		preg_match('/>Delegate (.+)</', $html, $matches);
		$legislator['name'] = trim($matches[1]);
		unset($matches);
		
		// Remove any nickname
		$legislator['name'] = preg_replace('/ \(([A-Za-z]+)\) /', ' ', $legislator['name']);

		// Preserve this version of their name as their formal name
		$legislator['name_formal'] = $legislator['name'];

		// Remove any suffix
		$suffixes = array('Jr.', 'Sr.', 'I', 'II', 'III', 'IV');
		foreach ($suffixes as $suffix)
		{
			if (substr( ($legislator['name']), strlen($suffix)*-1, strlen($suffix) ) == $suffix)
			{
				$legislator['name'] = trim(substr($legislator['name'], 0, strlen($suffix)*-1));
			}
		}

		// Set aside the legislator's name in this format for use when creating the shortname
		$shortname = $legislator['name'];

		/*
		 * Get delegate's preferred first name.
		 */
		preg_match('/>Preferred Name: ([a-zA-Z]+)</', $html, $matches);
		if (!empty($matches))
		{
			$legislator['nickname'] = trim($matches[1]);
			unset($matches);
		}

		// Save the legislator's name in Lastname, Firstname format.
		if (isset($legislator['nickname']))
		{
			$legislator['name'] = substr($legislator['name'], strripos($legislator['name'], ' ')+1)
				. ', ' . $legislator['nickname'];
		}
		else
		{
			$legislator['name'] = substr($legislator['name'], strripos($legislator['name'], ' ')+1)
				. ', ' . substr($legislator['name'], 0, strripos($legislator['name'], ' ')*-1);
		}

		/*
		 * Format delegate's shortname.
		 */
		preg_match_all('([A-Z]{1})', $shortname, $matches);
		$legislator['shortname'] = implode('', array_slice($matches[0], 0, -1));
		$tmp = explode(', ', $legislator['name']);
		$legislator['shortname'] .= $tmp[0];
		$legislator['shortname'] = strtolower($legislator['shortname']);

		/*
		 * Get email address.
		 */
		preg_match('/mailto:(.+)"/', $html, $matches);
		$legislator['email'] = trim($matches[1]);
		unset($matches);

		/*
		 * Get legislator start date.
		 */
		preg_match('/Member Since: (.+)</', $html, $matches);
		$legislator['start_date'] = date('Y-m-d', strtotime(trim($matches[1])));
		unset($matches);

		/*
		 * Get district number.
		 */
		preg_match('/([0-9]{1,2})([a-z]{2}) District/', $html, $matches);
		$legislator['district_number'] = $matches[1];
		unset($matches);

		/*
		 * Get capitol office address.
		 */
		preg_match('/Room Number:<\/span> ([E,W]([0-9]{3}))/', $html, $matches);
		$legislator['richmond_address'] = $matches[1];
		unset($matches);

		/*
		 * Get capitol phone number.
		 */
		preg_match('/Office:([\S\s]*)(\(804\) ([0-9]{3})-([0-9]{4}))/', $html, $matches);
		$legislator['richmond_phone'] = substr(str_replace(') ', '-', $matches[2]), 1);
		unset($matches);

		/*
		 * Get district address.
		 */
		$tmp = 'Address: ' . $dom->find('div[class=memBioOffice]', 1)->plaintext;
		$legislator['district_address'] = str_replace('Address: District Office ', '', preg_replace('/\s{2,}/', ' ', $tmp));
		if (stripos($legislator['district_address'], 'Office:') !== false)
		{
			$legislator['district_address'] = substr($legislator['district_address'], 0, stripos($legislator['district_address'], 'Office:'));

		}

		/*
		 * Get district phone number.
		 */
		$tmp = 'Address: ' . $dom->find('div[class=memBioOffice]', 1)->plaintext;
		preg_match('/(\(804\) ([0-9]{3})-([0-9]{4}))/', $html, $matches);
		$legislator['district_phone'] = substr(str_replace(') ', '-', $matches[0]), 1);

		/*
		 * Get legislator photo.
		 */
		preg_match('/https:\/\/memdata\.virginiageneralassembly\.gov\/images\/display_image\/H[0-9]{4}/', $html, $matches);
		$legislator['photo_url'] = $matches[0];
		unset($matches);

		/*
		 * Get gender.
		 */
		preg_match('/Gender:<\/span> ([A-Za-z]+)/', $html, $matches);
		$legislator['sex'] = strtolower($matches[1]);
		unset($matches);

		/*
		 * Get race.
		 */
		preg_match('/Race\(s\):<\/span> (.+)</', $html, $matches);
		$legislator['race'] = trim(strtolower($matches[1]));
		$races = array(
			'african american' => 'black',
			'caucasian' => 'white',
			'Asian American' => 'asian',
			'Asian American, Indian' => 'asian',
			'Hispanic, Latino' => 'latino',
			'none given' => '',
		);
		foreach ($races as $find => $replace)
		{
			if ($legislator['race'] == $find)
			{
				$legislator['race'] = $replace;
				break;
			}
		}
		unset($matches);

		/*
		 * Get political party.
		 */
		preg_match('/distDescriptPlacement">([D,I,R]{1}) -/', $html, $matches);
		$legislator['party'] = trim($matches[1]);
		unset($matches);

		/*
		 * Get personal website.
		 */
		preg_match('/Delegate\'s Personal Website[\s\S]+(http(.+))"/U', $html, $matches);
		print_r($matches);
		$legislator['website'] = trim($matches[1]);
		unset($matches);

		// MISSING, REQUIRED FIELDS
		//name_formatted (incl. placename)
		//lis_shortname
	}

	elseif ($chamber == 'senate')
	{

		####
		#### SENATOR DATA AVAILABLE AS JSON
		#### 
		####

		/*
		 * Fetch the HTML.
		 */
		$url = 'https://whosmy.virginiageneralassembly.gov/index.php/legislator';
		$html = file_get_contents($url);
		echo $html;

		/*
		 * Extract JSON from the data.
		 */
		preg_match('/senatorData">(.+)<\/div>/', $html, $matches);
		echo 'matches';
		print_r($matches);
		unset($matches);

	}

	return $legislator;

}

$data = get_legislator_data('senate', '259');
var_dump($data);
die();

/*
 * Retrieve a list of all active delegates' names and IDs. Though that's not *quite* right.
 * Within a couple of weeks of the election, the House's's website pretends that the departing
 * delegates are already out office. New delegates are listed, departing ones are gone. To
 * avoid two solid months of errors, instead we get a list of delegates with no end date.
 */
$sql = 'SELECT name, chamber, lis_id
		FROM representatives
		WHERE chamber="house"
			AND date_ended IS NULL';
$stmt = $dbh->prepare($sql);
$stmt->execute();
$known_legislators = $stmt->fetchAll(PDO::FETCH_OBJ);

/*
 * Now get a list of senators. The Senate doesn't change their list of members until the day
 * that a new session starts, so we need to use a slightly different query for them.
 */
$sql = 'SELECT name, chamber, lis_id
		FROM representatives
		WHERE chamber="senate"
			AND date_started <= NOW()
			AND (
				date_ended IS NULL
				OR
				date_ended >= NOW())';
$stmt = $dbh->prepare($sql);
$stmt->execute();
$known_legislators = array_merge($known_legislators, $stmt->fetchAll(PDO::FETCH_OBJ));

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
foreach ($known_legislators as &$known_legislator)
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
