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
		//$lis_id = 'H' . str_pad($lis_id, 4, '0', STR_PAD_LEFT);

		/*
		 * Fetch the HTML and save parse the DOM.
		 */
		$url = 'https://virginiageneralassembly.gov/house/members/members.php?ses=' . SESSION_YEAR
			. '&id=' . $lis_id;
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

		$legislator['chamber'] = 'house';
		$legislator['lis_id'] = $lis_id;

		/*
		 * Get delegate name.
		 */
		preg_match('/>Delegate (.+)</', $html, $matches);
		$legislator['name'] = trim($matches[1]);
		unset($matches);

		/*
		 * When delegates are elected, but not yet seated, LIS will call them "Delegate Elect."
		 * Remove "Elect" if it appears in the name.
		 */
		$legislator['name'] = str_replace('Elect ', '', $legislator['name']);

		/*
		 * Sometimes we wind up with double spaces in legislators' names, so remove those.
		 */
		$legislator['name'] = preg_replace('/\s{2,}/', ' ', $legislator['name']);
		
		/*
		 * Remove any nickname
		*/
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

		/*
		 * Set aside the legislator's name in this format for use when creating the shortname
		 */
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
		$legislator['name'] = preg_replace('/\s{2,}/', ' ', $legislator['name']);

		/*
		 * Format delegate's shortname.
		 */
		preg_match_all('(\b\w)', $shortname, $matches);
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
		$legislator['date_started'] = date('Y-m-d', strtotime(trim($matches[1])));
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
		$legislator['address_richmond'] = $matches[1];
		unset($matches);

		/*
		 * Get capitol phone number.
		 */
		preg_match('/Office:([\S\s]*)(\(804\) ([0-9]{3})-([0-9]{4}))/', $html, $matches);
		$legislator['phone_richmond'] = substr(str_replace(') ', '-', $matches[2]), 1);
		unset($matches);

		/*
		 * Get district address.
		 */
		$tmp = 'Address: ' . $dom->find('div[class=memBioOffice]', 1)->plaintext;
		$legislator['address_district'] = str_replace('Address: District Office ', '', preg_replace('/\s{2,}/', ' ', $tmp));
		if (stripos($legislator['address_district'], 'Office:') !== false)
		{
			$legislator['address_district'] = trim(substr($legislator['address_district'], 0, stripos($legislator['address_district'], 'Office:')));
		}
		$legislator['address_district'] = trim ($legislator['address_district']);
		if ($legislator['address_district'] == ',')
		{
			unset($legislator['address_district']);
		}

		/*
		 * Get district phone number.
		 */
		$tmp = 'Address: ' . $dom->find('div[class=memBioOffice]', 1)->plaintext;
		preg_match('/(\(804\) ([0-9]{3})-([0-9]{4}))/', $html, $matches);
		$legislator['phone_district'] = substr(str_replace(') ', '-', $matches[0]), 1);

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
		$legislator['website'] = trim($matches[1]);
		if ($legislator['website'] == 'https://whosmy.virginiageneralassembly.gov/')
		{
			unset($legislator['website']);
		}
		unset($matches);

		/*
		 * Turn district number into a district ID
		 */
		$district = new District;
		$d = $district->info('house', $legislator['district_number']);
		$legislator['district_id'] = $d['id'];

		/*
		 * Get place name
		 */
		preg_match('/<td>(.+), VA\s+([0-9]{5})/s', $html, $matches);
		$legislator['place'] = $matches[1];

		/*
		 * Create formatted name
		 */
		$legislator['name_formatted'] = 'Del. ' . pivot($legislator['name']) . ' (' .
			$legislator['party'] . '-' . $legislator['place'] . ')';

	}

	elseif ($chamber == 'senate')
	{

		/*
		 * Fetch the HTML.
		 */
		$url = 'https://whosmy.virginiageneralassembly.gov/index.php/legislator';
		$html = file_get_contents($url);

		/*
		 * Extract JSON from the data.
		 */
		preg_match('/senatorData">(.+)<\/div>/', $html, $matches);
		$senators_json = $matches[1];
		unset($matches);
		$senators = json_decode($senators_json);
		if (false === $senators)
		{
			return false;
		}

		foreach ($senators as $senator_data)
		{
			$senator_data->member_key = trim($senator_data->member_key);
			if ($senator_data->member_key == $lis_id)
			{
				$senator = $senator_data;
				break;
			}
		}

		if (!isset($senator))
		{
			return false;
		}

		/*
		 * Use the correct names for elements; example JSON response:
		 * "last_name": "Mason",
		 * "first_name": "T. Montgomery",
		 * "middle_name": "\"Monty\"",
		 * "suffix": "",
		 * "district": "1",
		 * "party": "D",
		 * "capitol_phone": "(804) 698-7501",
		 * "district_phone": "(757) 229-9310",
		 * "email_address": "district01@senate.virginia.gov",
		 * "member_key": "S102 "
		 */
		$legislator = [];
		$legislator['name'] = $senator->last_name . ', ' . $senator->first_name;
		$legislator['name_formal'] = $senator->first_name . ' ' . $senator->middle_name  . ' ' . $senator->last_name;
		$legislator['name_formal'] = preg_replace('/\s{2,}/', ' ', $legislator['name_formal']);
		// Figure out how to get the LIS shortname
		$legislator['lis_shortname'] = '';
		$legislator['lis_id'] = substr(trim($senator->member_key), 1);
		$legislator['chamber'] = 'senate';
		$legislator['party'] = trim($senator->party);
		$legislator['email'] = trim($senator->email_address);
		$legislator['district_number'] = trim($legislator->district);

		/*
		 * Fetch the HTML and save parse the DOM.
		 */
		$url = 'https://apps.senate.virginia.gov/Senator/memberpage.php?id=' . $lis_id;
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
		 * Get district number.
		 */
		preg_match('/, District ([0-9]{1,2})/', $html, $matches);
		$legislator['district_number'] = $matches[1];
		unset($matches);

		/*
		 * Get legislator photo.
		 */
		preg_match('/(Senator\/images\/member_photos\/[a-zA-Z0-9-]+)/', $html, $matches);
		$legislator['photo_url'] = 'https://apps.senate.virginia.gov/' . trim($matches[0]);
		unset($matches);

		/*
		 * Get legislator biography.
		 */
		preg_match('/Biography(.+?)<div class="lrgblacktext">(.+?)<\/div>/s', $html, $matches);
		$legislator['bio'] = trim($matches[2]);
		$legislator['bio'] = str_replace("\n", ' ', $legislator['bio']);
		$legislator['bio'] = preg_replace('/\s+/', ' ', $legislator['bio']);
		unset($matches);

		/*
		 * Get district address.
		 */
		preg_match('/District Office(.+)<div class="lrgblacktext">(.+)Phone/s', $html, $matches);
		$legislator['address_district'] = trim($matches[2]);
		$legislator['address_district'] = preg_replace('/\s+/', ' ', $legislator['address_district']);
		$legislator['address_district'] = trim(str_replace('<br />', "\n", $legislator['address_district']));
		unset($matches);

		/*
		 * Get place name
		 */
		preg_match('/(.+)\n(.+), VA/s', $legislator['address_district'], $matches);
		$legislator['place'] = trim($matches[2]);

		/*
		 * Create formatted name
		 */
		$legislator['name_formatted'] = 'Sen. ' . pivot($legislator['name']) . ' (' .
			$legislator['party'] . '-' . $legislator['place'] . ')';

		/*
		 * Get Richmond office number.
		 */
		preg_match('/Room No: ([0-9]+)/', $html, $matches);
		$legislator['address_richmond'] = trim($matches[1]);
		unset($matches);

		/*
		 * Get Richmond phone number.
		 */
		preg_match('/Session Office<\/strong>(.+?)Phone: \(804\) ([0-9]{3})-([0-9]{4})/s', $html, $matches);
		$legislator['phone_richmond'] = '804-' . $matches[2] . '-' . $matches[3];
		unset($matches);

		/*
		 * Get District phone number.
		 */
		preg_match('/District Office<\/strong>(.+?)Phone: \(([0-9]{3})\) ([0-9]{3})-([0-9]{4})/s', $html, $matches);
		if (count($matches) == 5)
		{
			$legislator['phone_district'] = $matches[2] . '-' . $matches[3] . '-' . $matches[4];
		}
		unset($matches);

		/*
		 * Format senator's shortname.
		 */
		preg_match_all('([A-Z]{1})', $legislator['name_formal'], $matches);
		$legislator['shortname'] = implode('', array_slice($matches[0], 0, -1));
		$tmp = explode(', ', $legislator['name']);
		$legislator['shortname'] .= $tmp[0];
		$legislator['shortname'] = strtolower($legislator['shortname']);

		/*
		 * Turn district number into a district ID
		 */
		$district = new District;
		$d = $district->info('senate', $legislator['district_number']);
		$legislator['district_id'] = $d['id'];

	}
	
	return $legislator;

}

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
 * First see if we have records of any legislators who are not currently in office.
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
 * Second, see there are any listed delegates or senators who are not in our records.
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

		$data = get_legislator_data('senate', $lis_id);
		$required_fields = array(
			'name_formal',
			'name',
			'name_formatted',
			'shortname',
			'lis_shortname',
			'chamber',
			'district_id',
			'date_started',
			'party',
			'lis_id',
			'email',
			'phone_district',
			'phone_richmond',
			'place'
		);
		foreach ($required_fields as $field)
		{
			if ( !isset($data[$field]) || empty($data[$field]) )
			{
				echo 'Error: ' . $field . ' is missing for ' . $data['name'] . "\n";
			}
		}

		print_r($data);
		
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
		$log->put('Delegate missing from the database: ' . $name . ' (https://virginiageneralassembly.gov/house/members/members.php?ses=' .
			SESSION_YEAR . '&id='. $lis_id . ')', 6);
		$data = get_legislator_data('house', $lis_id);

		$required_fields = array(
			'name_formal',
			'name',
			'name_formatted',
			'shortname',
			'chamber',
			'district_id',
			'date_started',
			'party',
			'lis_id',
			'email',
			'place'
		);
		foreach ($required_fields as $field)
		{
			if ( !isset($data[$field]) || empty($data[$field]) )
			{
				echo 'Error: ' . $field . ' is missing for ' . $data['name'] . "\n";
			}
		}
		print_r($data);
	}

}
