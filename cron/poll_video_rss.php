<?php

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

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

$sources = array(
			'house' => 'http://virginia-house.granicus.com/VPodcast.php?view_id=3',
			'senate' => 'http://virginia-senate.granicus.com/VPodcast.php?view_id=3'
			);

foreach ($sources as $chamber => $url)
{

	$cache_file = '.video_rss_' . $chamber;

	/*
	 * Retrieve the RSS.
	 */
	$xml = get_content($url);

	if ($xml === FALSE)
	{
		echo 'RSS could not be retrieved';
		continue;
	}

	/*
	 * Turn the XML into an object.
	 */
	$xml = simplexml_load_string($xml);

	/*
	 * Get the cached GUIDs.
	 */
	$guid_cache = array();
	if (file_exists($cache_file))
	{

		$raw_cache = file_get_contents($cache_file);
		if ($raw_cache !== FALSE)
		{
			$guid_cache = unserialize($raw_cache);
		}

	}

	/*
	 * Get all GUIDs from the XML.
	 */
	$guids = array();
	foreach ($xml->channel->item as $item)
	{
		$guids[] = (string) $item->guid;
	}

	/*
	 * If no GUIDs are new, then skip to the next chamber.
	 */
	$new_guids = array_diff($guids, $guid_cache);
	if (count($new_guids) == 0)
	{
		continue;
	}

	/*
	 * We'll keep our new videos in this array.
	 */
	$videos = array();

	/*
	 * Iterate through each new GUID, to find it in the XML.
	 */
	foreach ($new_guids as $guid)
	{
		
		/*
		 * Iterate through each XML item, to find this GUID.
		 */
		foreach ($xml->channel->item as $item)
		{

			if ($item->guid == $guid)
			{

				/*
				 * Figure out the date of this video.
				 */
				$timestamp = strtotime(end(explode(' - ', $item->title)));
				if ($timestamp === FALSE)
				{
					continue;
				}
				$date = date('Ymd', $timestamp);

				/*
				 * Figure out if this is a committee meeting or a floor session.
				 */
				if (stripos($item->title, 'Regular Session') !== FALSE)
				{
					$type = 'floor';
				}
				else
				{
					$type = 'committee';
				}

				/*
				 * If it's a committee, get the committee name.
				 * 
				 * Here is an example of the contents of the title tag, for a committee:
				 * 
				 * Finance (Comm Room B) - January 17, 2018 - 9:00 AM - Jan 17, 2018
				 *
				 * Though be warned that titles can also be like this:
				 * 
				 * January 10, 2018 - Governor Terry McAuliffe&apos;s address to the 2018 Joint General Assembly - Jan 10, 2018
				 */
				if ($type == 'committee')
				{
					$title_parts = explode(' - ', $item->title);
					$committee = $title_parts[0];
					$meeting_time = $title_parts[2];
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
				 * Append commitee information.
				 */
				if ($type == 'committee')
				{
					$video_data['committee'] = $committee;
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
	 * If we found any videos, record them to SQS for later processing.
	 */
	if (count($videos) > 0)
	{

		foreach ($videos as $video)
		{

			/*
			 * Log this to SQS.
			 */
			$sqs_client->sendMessage([
				'MessageGroupId'			=> '1',
				'MessageDeduplicationId'	=> mt_rand(),
			    'QueueUrl'    				=> 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo',
			    'MessageBody' 				=> json_encode($video)
			]);

			$log->put('Machine found new video, for ' . $video->date . ', at ' . $video->url
				. '. Starting video processor.', 5);

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


	}

	/*
	 * Write all item GUIDs back to the cache file.
	 */ 
	if (file_put_contents($cache_file, serialize($guids)) === FALSE)
	{
		echo 'Could not cache GUIDs.';
	}

}
