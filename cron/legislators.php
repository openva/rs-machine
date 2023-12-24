<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/*
 * Connect to the database
 */
$database = new Database;
$db = $database->connect();
global $db;

/*
 * Instantiate the logging class
 */
$log = new Log;

/*
 * Use a DOM parser for the screen-scraper.
 */
use Sunra\PhpSimple\HtmlDomParser;

class Updater
{

	private $log;

	public function __construct(Log $log)
	{
        $this->log = $log;
    }

	/*
	 * fetch_photo()
	 *
	 * Retrieves a legislator photo from a provided URL and stores it.
	 */
	public function fetch_photo($url, $shortname)
	{

		if (empty($url) && empty($shortname))
		{
			return false;
		}

		/*
		 * Retrieve the photo from the remote server
		 */
		$photo = file_get_contents($url);
		
		if ($photo == false)
		{
			return false;
		}

		/*
		 * Store the file without an extension (we don't know the image format)
		 */
		$filename = $shortname;
		if (file_put_contents($filename, $photo) == false)
		{
			return false;
		}

		/*
		 * Try to identify the file format
		 */
		$filetype = mime_content_type($filename);
		if (stristr($filetype, 'image/jpeg'))
		{
			rename($filename, $filename.'.jpg');
			$filename = $filename.'.jpg';
		}
		elseif (stristr($filetype, 'image/png'))
		{
			rename($filename, $filename.'.png');
			$filename = $filename.'.png';
		}

		return $filename;
		
	}

	/*
	 * deactivate_legislator()
	 * 
	 * Sets a legislator as having left office.
	 */
	public function deactivate_legislator($id)
	{

		if (!isset($id))
		{
			return false;
		}

		/*
		 * LIS IDs are preceded with an H or an S, but we don't use those within the database,
		 * so strip those out.
		 */
		$id = preg_replace('/[H,S]/', '', $id);

		/*
		* Determine what date to use to mark the legislator as no longer in office.
		* 
		* If it's November or December of an odd-numbered year, then the legislator's end date is
		* the day before the next session starts.
		*/
		if (date('m') >= 11 && date('Y') % 2 == 1)
		{

			/*
			* See if we know when the next session starts.
			*/
			$sql = 'SELECT date_started
					FROM sessions
					WHERE date_started > now()';
			$stmt = $GLOBALS['db']->prepare($sql);
			$stmt->execute();
			$session = $stmt->fetch(PDO::FETCH_OBJ);
			if (count($session) > 0)
			{
				$date_ended = $session->date_started;
			}

			/*
			 * If we don't know when the next session starts, go with January 1.
			 */
			else
			{
				$date_ended = date('Y') + 1 . '-01-01';
			}
		}

		/*
		 * If this is not post-election, just make the date yesterday.
		 */
		else
		{
			$date_ended = date('Y-m-d', strtotime('-1 day'));
		}

		$sql = 'UPDATE representatives
				SET date_ended="' . $date_ended . '"
				WHERE lis_id="'. $id .'"';
		$stmt = $GLOBALS['dbh']->prepare($sql);
		$result = $stmt->execute();

		return $result;

	} // deactivate_legislator()

	/*
	 * add_legislator()
	 *
	 * Creates a new record for a legislator, requiring as input all data about the legislator to be
	 * added to the database. All array keys must have the same names as the database columns.
	 */
	public function add_legislator($legislator)
	{

		if (!isset($legislator) || !is_array($legislator))
		{
			return false;
		}

		/*
		* All of these values must be defined in order to create a record.
		*/
		$required_fields = array(
			'name_formal' => true,
			'name' => true,
			'name_formatted' => true,
			'shortname' => true,
			'chamber' => true,
			'district_id' => true,
			'date_started' => true,
			'party' => true,
			'lis_id' => true,
			'email' => true,
		);

		/*
		 * If any required values are missing, give up.
		 */
		$missing_fields = array_diff_key($required_fields, $legislator);
		if (count($missing_fields) > 0)
		{

			$this->log->put('Missing one or more required fields (' . implode(',', $missing_fields)
				. ') to add a record for ' . $legislator['name_formal'], 6);
			return false;

		}

		/*
		 * Make sure that there is not already a record for this shortname.
		 */
		$sql = 'SELECT *
				FROM representatives
				WHERE shortname="' . $legislator['shortname'] . '"';
		$stmt = $GLOBALS['dbh']->prepare($sql);
		$stmt->execute();
		$existing = $stmt->fetchAll(PDO::FETCH_OBJ);

		if (count($existing) > 0)
		{

			$error = 'Not creating a record for ' . $legislator['name_formatted'] .' because '
				. ' there is already a record for ' . $legislator['shortname'] . ' in the '
				. 'database. This legislator must be added manually. Use this info: ';
			foreach ($legislator as $key => $value)
			{
				$error .= $key . ': ' . $value . "\n";
			}
			$this->log->put($error, 6);

			return false;

		}

		/*
		 * LIS IDs are preceded with an "H" or an "S," but we don't use those within the
		 * database, so strip that out.
		 */
		$legislator['lis_id'] = preg_replace('/[H,S]/', '', $legislator['lis_id']);

		/*
		 * Build the SQL query
		 */
		$sql = 'INSERT INTO representatives SET ';
		foreach ($legislator as $key=>$value)
		{
			$sql .= $key.'="' . $value . '", ';
		}

		$sql .= 'date_created=now()';

		/*
		 * Insert the legislator record
		 */
		$stmt = $GLOBALS['dbh']->prepare($sql);
		$result = $stmt->execute();
		if ($result == false)
		{
			$this->log->put('Error: Legislator record could not be added.' . "\n" . $sql . "\n", 6);
			return false;
		}
		
		return true;

	}

	/*
	 * fetch_legislator_data()
	 * 
	 * Retrieves data about a legislator from the General Assembly's website, requiring as input the
	 * chamber name (house or senate) and the legislator's LIS ID.
	 */
	public function fetch_legislator_data($chamber, $lis_id)
	{

		if ( empty($chamber) || empty($lis_id) )
		{
			return false;
		}

		/*
		 * Fetch delegate information
		 */
		if ($chamber == 'house')
		{

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
			$legislator['lis_id'] = preg_replace('[HS]', '', $lis_id);

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
			 * Remove any nickname.
			 */
			$legislator['name'] = preg_replace('/ \(([A-Za-z]+)\) /', '', $legislator['name']);

			/*
			 * Sometimes we wind up with double spaces in legislators' names, so remove those.
			 */
			$legislator['name'] = trim(preg_replace('/\s{2,}/', ' ', $legislator['name']));

			/*
			 * Preserve this version of their name as their formal name
			 */
			$legislator['name_formal'] = trim($legislator['name']);

			/*
			 * Remove any suffix
			 */
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
			 * Remove any middle initials, but only if they're surrounded by spaces on either side.
			 * (Otherwise, e.g. "K.C. Smith" would become "Smith.")
			 */
			$legislator['name'] = preg_replace('/ [A-Z]\. /', ' ', $legislator['name']);

			/*
			 * Get delegate's preferred first name.
			 */
			preg_match('/>Preferred Name: ([a-zA-Z]+)</', $html, $matches);
			if (!empty($matches))
			{
				$legislator['nickname'] = trim($matches[1]);
				unset($matches);
			}

			/*
			 * Save the legislator's name in Lastname, Firstname format.
			 */
			if (isset($legislator['nickname']))
			{
				$legislator['name'] = substr($legislator['name'], strripos($legislator['name'], ' ')+1)
					. ', ' . $legislator['nickname'];
			}
			else
			{
				$last_space = strripos($legislator['name'], ' ');

				if ($last_space !== false)
				{
					$legislator['name'] =
						substr($legislator['name'], $last_space + 1) .
						', ' .
						substr($legislator['name'], 0, $last_space);
				}
			}
			$legislator['name'] = preg_replace('/\s{2,}/', ' ', $legislator['name']);

			/*
			 * We no longer need a nickname.
			 */
			if (isset($legislator['nickname']))
			{
				unset($legislator['nickname']);
			}

			/*
			 * Format delegate's shortname.
			 */
			preg_match_all('([A-Za-z-]+)', $shortname, $matches);
			$legislator['shortname'] = '';
			$i=0;
			while ($i+1 < count($matches[0]))
			{
				$legislator['shortname'] .= $matches[0][$i][0];
				$i++;
			}
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
			if (isset($matches[1]))
			{
				$legislator['address_richmond'] = $matches[1];
				unset($matches);
			}

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
			preg_match('/<th scope="col">District Office(.+)<td>([A-Za-z ]+), (VA|Virginia)(\s+)([0-9]{5})/sU', $html, $matches);
			if (isset($matches[2]))
			{
				$legislator['place'] = $matches[2];
			}

			/*
			 * Create formatted name
			 */
			$legislator['name_formatted'] = 'Del. ' . pivot($legislator['name']) . ' (' .
				$legislator['party'] . '-';
			// We don't always have the place name, due to incomplete LIS data
			if (!empty($legislator['place']))
			{
				$legislator['name_formatted'] .= $legislator['place'];
			}
			else
			{
				$legislator['name_formatted'] .= $legislator['district_number'];
			}
			$legislator['name_formatted'] .= ')';


			/*
			 * We no longer need the district number.
			 */
			unset($legislator['district_number']);

		} // fetch delegate

		/*
		 * Fetch senator data
		 */
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
			$legislator['lis_id'] = substr(trim($senator->member_key), 1);
			$legislator['chamber'] = 'senate';
			$legislator['party'] = trim($senator->party);
			$legislator['email'] = trim($senator->email_address);
			$legislator['district_number'] = trim($senator->district);

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
				$legislator['party'] . '-';
			
			/*
			 * We don't always have the place name, due to incomplete LIS data
			 */
			if (!empty($legislator['place']))
			{
				$legislator['name_formatted'] .= $legislator['place'];
			}
			else
			{
				$legislator['name_formatted'] .= $legislator['district_number'];
			}
			$legislator['name_formatted'] .= ')';
			

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

			/*
			 * We no longer need the district number.
			 */
			unset($legislator['district_number']);

		} // fetch senator

		/*
		 * Clean up or enhance data collected
		 *
		 * Instead of repeating identical data transformations for delegates and senators, perform
		 * common transformations here.
		 */

		 /*
		  * Get location coordinates
		  */
		$location = new Location();
		if (!empty($legislator['address_district']))
		{
			$location->address = $legislator['address_district'];
		}
		elseif (!empty($legislator['place']))
		{
			$location->address = $legislator['place'] . ', VA';
		}
		if ( !empty($legislator['place']) && $location->get_coordinates($legislator['place']) != false )
		{
			$legislator['latitude'] = round($location->latitude, 2);
			$legislator['longitude'] = round($location->longitude, 2);
		}

		/*
		 * Standardize racial descriptions
		 *
		 * This is a little weird. The House of Delegates rightly allows members to specify any
		 * racial descriptor for themselves. But our database only has a few crude racial labels,
		 * because we don't actually use them for anything on the site, and because the House
		 * long didn't provide racial identifiers (and the Senate still doesn't), requiring taking
		 * a guess when adding legislators. The correct thing to do here would be to modify
		 * the database to allow arbitrary descriptors to be entered. But I'm not prepared to do
		 * that at this moment, so instead I'm going to collapse provided race designators into
		 * a few overly simplistic categories. Again, this isn't actually being surfaced anywhere,
		 * so there's no impact.
		 */
		$race_map = array(
			'caucasian' => 'white',
			'hispanic' => 'latino',
			'african american' => 'black',
			'asian american' => 'asian',
			'middle eastern' => 'other'
		);
		if (!empty($legislator['race']))
		{
			if (array_key_exists($legislator['race'], $race_map))
			{
				$legislator['race'] = $race_map[$legislator{'race'}];
			}
			// If multiple races are listed, don't record anything
			elseif (stristr($legislator['race'], ','))
			{
				$legislator['race'] = '';
			}
		}

		/*
		 * Drop any array elements with blank contents.
		 */
		foreach ($legislator as $key => $value)
		{
			if (!empty($value))
			{
				$newLegislator[$key] = $value;
			}
		}
		
		return $legislator;

	}

}

/*
 * Retrieve a list of all active delegates' names and IDs. Though that's not *quite* right.
 * Within a couple of weeks of the election, the House's website pretends that the departing
 * delegates are already out office. New delegates are listed, departing ones are gone. To
 * avoid two solid months of errors, instead we get a list of delegates with no end date.
 */
$sql = 'SELECT name, chamber, lis_id
		FROM representatives
		WHERE chamber="house"
			AND date_ended IS NULL';
$stmt = $db->prepare($sql);
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
$stmt = $db->prepare($sql);
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
 * Invoke the updater class.
 */
$updater = new Updater($log);

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
			$log->put('Error: Sen. ' . pivot($known_legislator->name)
				. ' is no longer in office, but is still listed in the database.', 5);
			if ($updater->deactivate_legislator($id) == false)
			{
				$log->put('Error: ...but they couldn’t be marked as out of office.');
			}
		}
	}

	/*
	 * Check delegates.
	 */
	elseif ($known_legislator->chamber == 'house')
	{
		if (!isset($delegates[$id]))
		{
			$log->put('Error: Del. ' . pivot($known_legislator->name)
				. ' is no longer in office, but is still listed in the database.', 5);
			if ($updater->deactivate_legislator($id) == false)
			{
				$log->put('Error: ...but they couldn’t be marked as out of office.');
			}
		}
	}

}

/*
 * Get at least this minimum subset of fields for any senators and delegates that are not in our
 * records. (We use this list below.)
 */
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
	'email'
);

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

	/*
	 * If we've found any new senators, call that up, and scrape their basic data from LIS
	 */
	if ($match == FALSE && $name != 'Vacant')
	{

		$log->put('Found a new senator: ' . $name . ' (http://apps.senate.virginia.gov/Senator/memberpage.php?id=' . $lis_id . ')', 6);

		$data = $updater->fetch_legislator_data('senate', $lis_id);

		$errors = false;

		foreach ($required_fields as $field)
		{
			if ( !isset($data[$field]) || empty($data[$field]) )
			{
				$errors = true;
				$log->put('Required ' . $field . ' is missing for ' . $data['name_formatted']
					. '.', 6);
			}
		}

		if ($errors == false)
		{
			
			/*
			 * If there's a photo URL, save it in a separate variable and remove it from the
			 * legislator data, because the URL doesn't get inserted into the database.
			 */
			if (isset($data['photo_url']))
			{
				$photo_url = $data['photo_url'];
				$unset($data['photo_url']);
			}

			if ($updater->add_legislator($data) == false)
			{
				$log->put('Could not add ' . $data['name_formatted'] . ' to the system', 6);
				continue;
			}

			$photo_success = $updater->fetch_photo($photo_url, $data['shortname']);
			if ($photo_success == false)
			{
				$log->put('Could not retrieve photo of ' . $data['name_formatted'], 4);
			}
			else
			{
				$log->put('Photo of ' . $data['name_formatted'] . ' stored at ' . $photo_success
					. '. You need to manually commit it to the Git repo.', 5);
			}
			
		}
		else
		{
			$log->put('The new record for ' . $data['name_formatted'] .' was not added to the '
				. 'system, due to missing data.', 6);
			unset($errors);
		}
		
	}

}

/*
 * Third, see there are any listed delegates or senators who are not in our records.
 */
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
	
	/*
	 * If we've found any new delegates, call that up, and scrape their basic data from LIS
	 */
	if ($match == FALSE && $name != 'Vacant')
	{

		$log->put('Found a new delegate: ' . $name . ' (https://virginiageneralassembly.gov/house/members/members.php?ses=' .
			SESSION_YEAR . '&id='. $lis_id . ')', 6);
		$data = $updater->fetch_legislator_data('house', $lis_id);

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
			'email'
		);

		$errors = false;

		foreach ($required_fields as $field)
		{

			if ( !isset($data[$field]) || empty($data[$field]) )
			{
				$errors = true;
				$log->put('Error: Required ' . $field . ' is missing for ' . $data['name_formatted']
					. ', so they couldn’t be added to the system.', 6);
			}

		}

		if ($errors == false)
		{
			
			/*
			 * If there's a photo URL, save it in a separate variable and remove it from the
			 * legislator data, because the URL doesn't get inserted into the database.
			 */
			if (isset($data['photo_url']))
			{
				$photo_url = $data['photo_url'];
				unset($data['photo_url']);
			}
			
			if ($updater->add_legislator($data) == false)
			{
				$log->put('Could not add ' . $data['name_formatted'] . ' to the system', 6);
				continue;
			}

			$photo_success = $updater->fetch_photo($photo_url, $data['shortname']);
			if ($photo_success == false)
			{
				$log->put('Could not retrieve photo of ' . $data['name_formatted'], 4);
			}
			else
			{
				$log->put('Photo of ' . $data['name_formatted'] . ' stored at ' . $photo_success
					. '. You need to manually commit it to the Git repo.', 5);
			}
		}
		else
		{
			$log->put('The new record for ' . $data['name_formatted'] .' was not added to the '
				. 'system, due to missing data.', 6);
			unset($errors);
		}

	}

}
