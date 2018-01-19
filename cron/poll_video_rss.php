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
	 * See which GUIDs are new.
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

				/* If there's no hyphen (found in the date
				 * separator), then this isn't a video, and we can skip it.
				 */
				$pos = strpos($item->title, '-');
				if ($pos === FALSE)
				{
					continue;
				}
				$date = substr($item->title, 0, $pos);
				$timestamp = strtotime($date);
				$date = date('Ymd', $timestamp);

				$type = 'floor';

				/*
				 * Save data about this video.
				 */
				$videos[] = array(
					'date' => $date,
					'url' => (string) $item->enclosure['url'],
					'chamber' => $chamber,
					'type' => $type
				);

			}

		}

	}

	/*
	 * If we found any videos, retrieve them.
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
