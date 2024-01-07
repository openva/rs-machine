<?php

/*
DANGER, WILL ROBINSON!
If a bill has no placenames in it, we never mark it as being name-less. The result is that we run
a query over and over and over, always getting no results.
*/

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific
# page.
connect_to_db();

/*
 * Select all bills that contain a phrase concerning geography for which we don't already have
 * location records stored.
 */
$sql = 'SELECT bills.id, bills.number, bills.full_text, sessions.year
		FROM bills
		LEFT JOIN sessions
			ON bills.session_id=sessions.id
		WHERE
			(
				(full_text LIKE "% Town of%") OR (full_text LIKE "% City of%")
				OR (full_text LIKE "% County of%") OR (full_text LIKE "% Towns of%")
				OR (full_text LIKE "% Cities of%") OR (full_text LIKE "% Counties of%")
				OR (full_text LIKE "% County%") OR (full_text LIKE "% City%")
			)
		AND
			(SELECT COUNT(*)
			FROM bills_places
			WHERE bill_id=bills.id) = 0
		AND bills.session_id=' . SESSION_ID . '
		AND bills.date_created >= (CURDATE() - INTERVAL 3 DAY)
		ORDER BY RAND()
		LIMIT 10';
$result = mysql_query($sql);
if (mysql_num_rows($result) == 0)
{
	return;
}

/*
 * Connect to Memcached, as we may well be interacting with it during this session.
 */
$mc = new Memcached();
$mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);

/*
 * Set up for queries to OpenAI
 */
$api_key = OPENAI_KEY;
$endpoint = 'https://api.openai.com/v1/chat/completions';
$role = 'You are a helpful assistant who identifies the names of places mentioned in '
	. 'the languages of legislation before the Virginia General Assembly. Given the text '
	. 'of a bill, you will extract the name of every Virginia county, city, and town mentioned, '
	. 'creating a list of them. You will separate each place name by commas. You will use the full name, of each place '
	. '(e.g. "City of Fairfax," "County of Fairfax," or "Town of Scottsville.") If no places '
	. 'are in the text at all, remain silent.' . "\n\n";

/*
 * Create an initial connection to the endpoint, to be reused on each loop
 */
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key
]);

/*
 * Iterate through the bills.
 */
while ($bill = mysql_fetch_array($result))
{

	$bill = array_map('stripslashes', $bill);

	/*
	 * Get bill information from the API, take all of the text that's changing, and put it into
	 * a single string. If there's no diff of changed text, then use the bill's full text.
	 */
	$bill_info = file_get_contents('https://api.richmondsunlight.com/1.1/bill/' . $bill['year'] . '/' . $bill['number'] . '.json');
	if ($bill_info == FALSE)
	{
		continue;
	}
	$bill_info = json_decode($bill_info);
	if ( empty($bill_info->changes) || count($bill_info->changes) == 0 )
	{
		$prompt = strip_tags($bill_info->full_text);
	}
	else
	{
		$prompt = '';
		foreach ($bill_info->changes as $change)
		{
			$prompt .= $change->text .= "\n";
		}
	}

	if (strlen($prompt) < 8)
	{
		continue;
	}

    $data = [
        'model' => 'gpt-4-1106-preview',
        'messages' => [
            ['role' => 'system', 'content' => $role],
            ['role' => 'user', 'content' => 'Please extract place names from the following text: '
				. $prompt]
        ]
    ];

	/*
     * Submit query
     */
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    if (curl_errno($ch))
    {
        echo 'cURL error: ' . curl_error($ch);
        $log->put('ERROR: Could not query OpenAI API, with this failure: ' . curl_error($ch), 3);
    }

   /*
    * Use the response
    */
	$response = json_decode($response, true);
    if (!isset($response['choices'][0]['message']['content']))
	{
		continue;
	}

	$generated_text = trim($response['choices'][0]['message']['content']);

	/*
	 * These responses indicate that ChatGPT hasn't found any place names.
	 */
	if (empty($generated_text))
	{
		continue;
	}
	$negative_responses = [ 'mentions', 'mentioned', 'provided text', 'specific'];
	foreach ($negative_responses as $negative_response)
	{
		if (stripos($generated_text, $negative_response) !== false)
		{
			continue(2);
		}
	}

	$places = explode(', ', $generated_text);

	/*
	 * Iterate through each returned place
	 */
	foreach ($places as $place)
	{

		/*
		 * We need different queries for different types of municipalities
		 */
		if (stripos($place, 'County') !== false)
		{

			if (stripos($place, 'County of') !== false)
			{
				$place = preg_replace('/County of (.+)/', '$1 County', $place);
			}

			$sql = 'SELECT latitude, longitude
					FROM gazetteer
					WHERE
						name="' . $place . '" AND
						municipality IS NULL';
		}
		elseif (stripos($place, 'City of ') !== false)
		{
			$place = str_replace('City of ', '', $place);
			$sql = 'SELECT latitude, longitude
					FROM gazetteer
					WHERE
						name="' . $place . '" AND
						municipality IS NOT NULL';
		}
		elseif (stripos($place, 'Town of ') !== false)
		{
			$place = str_replace('Town of ', '', $place);
			$sql = 'SELECT latitude, longitude
					FROM gazetteer
					WHERE
						name="' . $place . '" AND
						municipality IS NOT NULL';
		}

		$town_result = mysql_query($sql);

		/*
		 * If there's no result, or if there's more than one result (which we have no way to)
		 * pick between, skip this town.
		 */
		if ( ($town_result == false) || (mysql_num_rows($town_result) > 1) )
		{
			continue;
		}

		$coordinates = mysql_fetch_array($town_result);
	
		$sql = 'INSERT INTO bills_places
				SET
					bill_id=' . $bill['id'] . ',
					placename="' . addslashes($place) . '",
					latitude=' . $coordinates['latitude'] . ',
					longitude=' . $coordinates['longitude'];
		$place_result = mysql_query($sql);
		if ($place_result == false)
		{
			$log->put('Error: Could not add place names for ' . strtoupper($bill['number']), 4);
		}
		
	}

	/*
	 * Clear the bill from Memcached.
	 */
	$mc->delete('bill-' . $bill['id']);

	$log->put('Identified place names in ' . strtoupper($bill['number']), 2);

}

/*
 * Shut down the cURL connection.
 */
curl_close($ch);
