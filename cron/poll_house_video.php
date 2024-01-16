<?php

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

/*
 * Connect to the database.
 */
$database = new Database;
$db = $database->connect_mysqli();

/*
 * Instantiate the logging class.
 */
$log = new Log;

/*
 * Instantiate methods for AWS.
 */
use Aws\Sqs\SqsClient;
$credentials = new Aws\Credentials\Credentials(AWS_ACCESS_KEY, AWS_SECRET_KEY);
$sqs_client = new SqsClient([
	'profile'	=> 'default',
	'region'	=> 'us-east-1',
    'version'	=> '2012-11-05',
	'credentials'   => $credentials
]);

$today = date('Ymd');

$chamber = 'house';
$url = 'https://sg001-harmony.sliq.net/00304/Harmony/en/api/Data/GetRecentEndedEvents?lastModified=' 
	. $today . '000000000';

$cached_guids = '.video_guids_' . $chamber;
$cached_json = '.video_json_' . $chamber;

/*
 * Retrieve the JSON for the main listing.
 */
$json = get_content($url);

if ($json === FALSE)
{
	$log->put('Video JSON for House could not be retrieved.', 4);
	exit;
}

/*
 * Compare this file to the cached JSON.
 */
if ( file_exists($cached_json) && ( md5($json) == md5(file_get_contents($cached_json)) ) )
{
	$log->put('Video JSON for House is unchanged.', 1);
	exit;
}

/*
 * Cache the JSON.
 */
file_put_contents($cached_json, $json);

/*
 * Turn the JSON into an object.
 */
$video_list = json_decode($json);

/*
 * Get the cached GUIDs.
 */
$guid_cache = array();
if (file_exists($cached_guids))
{

	$raw_cache = file_get_contents($cached_guids);
	if ($raw_cache !== FALSE)
	{
		$guid_cache = unserialize($raw_cache);
	}

}

/*
 * Get all GUIDs from the JSON.
 */
$guids = array();
foreach ($video_list->ContentEntityDatas as $section)
{

	foreach ($section as $video)
    {
        $guids[] = $video->Id;
	}
	
}

/*
 * If no GUIDs are new, then we're done.
 */
$new_guids = array_diff($guids, $guid_cache);
if (count($new_guids) == 0)
{
	$log->put('No new House videos found.', 2);
}

/*
 * We'll keep our new videos in this array.
 */
$videos = array();

/*
 * Identify new videos and queue them for processing.
 */
foreach ($video_list->ContentEntityDatas as $section)
{

	foreach ($section as $video)
	{
		
		if (in_array($video->Id, $new_guids))
		{

			/*
			 * Extract the date of this video.
			 */
			$date = date('Ymd', strtotime($video->ActualStart));

			/*
			 * Figure out if this is a committee meeting or a floor session.
			 * 
			 * You may be thinking "the CommitteeId field is perfect for this." Yes, it is, but
			 * I regret to inform you that it is always null.
			 * 
			 * Not all "committee" videos are actually committee videos. There are non-floor videos
			 * that aren't committees, e.g. select committees, page ceremonies, etc.
			 * 
			 */
			if (
				$video->Location == 'House Chamber'
				||
				stripos($video->Title, 'Regular Session') !== FALSE
			)
			{
				$type = 'floor';
			}
			else
			{
				$type = 'committee';
			}

			/*
			 * If it's a committee, get the committee name.
			 */
			if ($type == 'committee')
			{

				$committee_name = str_replace('House', '', $video->Title);
				$meeting_time = substr($video->ActualStart, -8);

				/*
				 * Figure out the committee ID.
				 */
				$committee = new Committee;
				$committee->chamber = $chamber;
				$committee->name = $committee_name;
				$committee_id = $committee->get_id();
				if ($committee_id === FALSE)
				{
					$log->put('Could not identify committee “' . $committee_name
						. '” — skipping this video.', 3);
					continue;
				}
				$committee_id = $committee->id;

			}

			/*
			 * Figure out the video's URL.
			 */
			$video_url = 'https://sg001-harmony.sliq.net/00304/Harmony/en/PowerBrowser/PowerBrowserV2/'
				. $date . '/-1/' . $video->Id;
			
			/*
			 * We use cURL directly, instead of our get_content() function, so that we can fake a
			 * user agent here.
			 */
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)');
			curl_setopt($ch, CURLOPT_URL, $video_url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$video_html = curl_exec($ch);
			curl_close($ch);

			$pattern_match = '/"Url":"(.+\.mp4)"/';
			preg_match($pattern_match, $video_html, $matches);
			if (preg_match($pattern_match, $video_html, $matches) != true)
			{
				$log->put('Skipping video, because no video URL could be found on the web page: '
					. $video_url , 3);
				
				/*
				 * Remove this GUID from the list, so it won't be cached as completed, so it will
				 * be checked anew next time this runs.
				 */
				foreach ($guids as $key => $guid)
				{
					if ($guid == $video->Id)
					{
						unset($guids[$key]);
						$guids = array_values($guids);
					}
				}
                continue;
			}
			
			$url = $matches[1];

			/*
			 * Put together our final array of data about this video.
			 */
			$video_data =  array(
				'date' => $date,
				'url' => $url,
				'chamber' => $chamber,
				'type' => $type
			);

			/*
			 * Append committee information.
			 */
			if ($type == 'committee')
			{
				$video_data['committee'] = $committee_name;
				$video_data['committee_id'] = $committee_id;
				$video_data['meeting_time'] = $meeting_time;
			}

			/*
			 * Instruct the video process to complete all video processing steps.
			 */
			$video_data['step_all'] = TRUE;

			/*
			 * Append our array of video information to the list of all videos.
			 */
			$videos[] = $video_data;

		}

	}

}

/*
 * If we found no videos, we're done.
 */
if (count($videos) == 0)
{
	$log->put('No new legislative videos found.', 1);
	exit;
}

/*
 * But if we did find probably-new videos, iterate through them, see which are needed, and
 * queue them in SQS.
  */
$sql = 'SELECT chamber, date, committee_id,
		CASE
			WHEN committee_id IS NULL THEN "floor"
			WHEN committee_id IS NOT NULL THEN "committee" END
		AS type
		FROM files';
$result = mysqli_query($db, $sql);
if (mysqli_num_rows($result) == 0)
{
	$log->put('Could not get a list of existing videos to know if ' . $video['date'] . ', at '
		. $video['url'] . ', is new. Ending.', 5);
	exit(1);
}

$existing_videos = array();
while ($existing_video = mysqli_fetch_assoc($db, $result))
{
	$existing_videos[] = $existing_video;
}

foreach ($videos as &$video)
{

	/*
	 * If we have this same video in the database already, don't save it again.
	 */
	foreach ($existing_videos as $existing_video)
	{
		if (
			($video['date'] == str_replace('-', '', $existing_video['date']))
			&&
			($video['chamber'] == $existing_video['chamber'])
			&&
			($video['type'] == $existing_video['type'])
		)
		{
			if ($video['type'] == 'committee')
			{
				if ($video['committee_id'] == $existing_video['committee_id'])
				{
					continue(2);
				}
			}
			else
			{
				continue(2);
			}
		}
	}

	/*
	 * Log this to SQS.
	 */
	$sqs_client->sendMessage([
		'MessageGroupId'			=> '1',
		'MessageDeduplicationId'	=> mt_rand(),
		'QueueUrl'    				=> 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo',
		'MessageBody' 				=> json_encode($video)
	]);

	$log->put('Machine found new ' . (!empty($video['committee_id']) ? 'committee ' : '')
		. 'video, for ' . $video['date'] . ', for the ' . ucfirst($video['chamber'])
		. ', at: ' . $video['url']. '', 5);

}

/*
 * Start up the video-processing EC2 instance.
 */
$ec2_client = new Aws\Ec2\Ec2Client([
	'region'	=> 'us-east-1',
	'version'	=> '2016-11-15',
	'profile'	=> 'default',
	'key'		=> AWS_ACCESS_KEY,
	'secret'	=> AWS_SECRET_KEY
]);
$action = 'START';
$instanceIds = array('i-076d0d5ee323c4e83');
if ($action == 'START')
{
	$result = $ec2_client->startInstances([
		'InstanceIds' => $instanceIds,
	]);
}

$log->put('Starting video processor.', 5);

/*
 * Write all item GUIDs back to the cache file.
 */
if (file_put_contents($cached_guids, serialize($guids)) === FALSE)
{
	$log->put('Could not cache video GUIDs.', 4);
}
