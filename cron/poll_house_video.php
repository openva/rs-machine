<?php

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

/*
 * Connect to the database.
 */
$db = new Database;
$db->connect_old();

/*
 * Instantiate the logging class.
 */
$log = new Log;

/*
 * Instantiate methods for AWS.
 */
use Aws\Sqs\SqsClient;
$sqs_client = new SqsClient([
	'profile'	=> 'default',
	'region'	=> 'us-east-1',
    'version'	=> '2012-11-05',
	'key'		=> AWS_ACCESS_KEY,
	'secret'	=> AWS_SECRET_KEY
]);

$today = date('Ymd');

$chamber = 'house';
$url = 'https://sg001-harmony.sliq.net/00304/Harmony/en/View/EventListView/' . $today . '/-1';


$cached_guids = '.video_guids_' . $chamber;
$cached_json = '.video_json_' . $chamber;

/*
 * Retrieve the JSON for the main listing.
 */
$json = get_content($url);

if ($json === FALSE)
{
	$log->put('Video JSON for ' . ucfirst($chamber) . ' could not be retrieved.', 4);
	exit;
}

/*
 * Compare this file to the cached JSON.
 */
if ( file_exists($cached_json) && ( md5($json) == md5(file_get_contents($cached_json)) ) )
{
	$log->put('Video RSS for ' . ucfirst($chamber) . ' is unchanged.', 1);
	exit;
}

/*
 * Cache the JSON.
 */
file_put_contents($cached_json, $json);

/*
 * Turn the XML into an object.
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
foreach ($video_list->Weeks as $week)
{

	foreach ($week->ContentEntitityDatas as $video)
    {
        $guids[] = $item->Id;
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
foreach ($video_list->Weeks as $week)
{

	foreach ($week->ContentEntitityDatas as $video)
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
			if ($video->Location == 'House Chamber')
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

				$committee_name = $video->Title;
				$meeting_time = substr($video->ActualStart, -8);

				/*
				 * Figure out the committee ID.
				 */
				$committee = new Committee;
				$committee->chamber = $chamber;
				$committee->name = $committee_name;
				if ($committee->info() === FALSE)
				{
					$log->put('Could not identify committee “' . $committee_name
						. '” — skipping this video.', 3);
					continue;
				}
				$committee_id = $committee->id;

			}

			/*
			 * Put together our final array of data about this video.
			 */
			$video_data =  array(
				'date' => $date,
				'url' => (string) $item->enclosure['url'],
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
$result = mysql_query($sql);
if (mysql_num_rows($result) == 0)
{
	$log->put('Could not get a list of existing videos to know if ' . $video['date'] . ', at '
		. $video['url'] . ', is new. Ending.', 5);
	exit(1);
}

$existing_videos = array();
while ($existing_video = mysql_fetch_assoc($result))
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
	/*$sqs_client->sendMessage([
		'MessageGroupId'			=> '1',
		'MessageDeduplicationId'	=> mt_rand(),
		'QueueUrl'    				=> 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo',
		'MessageBody' 				=> json_encode($video)
	]);*/
	print_r($video);

	$log->put('Machine found new ' . (!empty($video['committee_id']) ? 'committee ' : '')
		. 'video, for ' . $video['date'] . ', for the ' . ucfirst($video['chamber'])
		. ', at: ' . $video['url']. '', 5);

}
///////////////
die();
///////////////

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
