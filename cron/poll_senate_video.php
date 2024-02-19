<?php

include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

/*
 * Connect to the database.
 */
$db = new Database();
$db->connect_old();

/*
 * Instantiate the logging class.
 */
$log = new Log();

/*
 * Instantiate methods for AWS.
 */
use Aws\Sqs\SqsClient;
$credentials = new Aws\Credentials\Credentials(AWS_ACCESS_KEY, AWS_SECRET_KEY);
$sqs_client = new SqsClient([
    'profile'   => 'default',
    'region'    => 'us-east-1',
    'version'   => '2012-11-05',
    'credentials'   => $credentials
]);

/*
 * The RSS feed for senate videos
 */
$url = 'http://virginia-senate.granicus.com/VPodcast.php?view_id=3';



$cached_guids = '.video_guids_senate';
$cached_xml = '.video_xml_senate';

/*
    * Retrieve the RSS.
    */
$xml = get_content($url);

if ($xml === false) {
    echo 'RSS could not be retrieved';
    exit();
}

/*
* Compare this file to the cached XML.
*/
if (file_exists($cached_xml) && ( md5($xml) == md5(file_get_contents($cached_xml)) )) {
    $log->put('Video RSS for senate is unchanged.', 1);
    exit();
}

/*
* Save the XML to our cache.
*/
file_put_contents($cached_xml, $xml);

/*
* Turn the XML into an object.
*/
$xml = simplexml_load_string($xml);

/*
* Get the cached GUIDs.
*/
$guid_cache = array();
if (file_exists($cached_guids)) {
    $raw_cache = file_get_contents($cached_guids);
    if ($raw_cache !== false) {
        $guid_cache = unserialize($raw_cache);
    }
}

/*
* Get all GUIDs from the XML.
*/
$guids = array();
foreach ($xml->channel->item as $item) {
    $guids[] = (string) $item->guid;
}

/*
* If no GUIDs are new, then we're done.
*/
if (count($guid_cache) > 0) {
    $new_guids = array_diff($guids, $guid_cache);
    if (count($new_guids) == 0) {
        exit();
    }
}

/*
* Otherwise, treat all GUIDs as new.
*/
else {
    $new_guids = $guids;
}

/*
* We'll keep our new videos in this array.
*/
$videos = array();

/*
* Iterate through each new GUID, to find in the XML.
*/
foreach ($new_guids as $guid) {
    /*
    * Iterate through each XML item, to find this GUID.
    */
    foreach ($xml->channel->item as $item) {
        if ($item->guid == $guid) {
            /*
            * Figure out the date of this video.
            */
            $timestamp = strtotime(end(explode(' - ', $item->title)));
            if ($timestamp === false) {
                continue;
            }
            $date = date('Ymd', $timestamp);

            /*
            * Figure out if this is a committee meeting or a floor session.
            */
            if (stripos($item->title, 'Regular Session') !== false) {
                $type = 'floor';
            } else {
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
            if ($type == 'committee') {
                $title_parts = explode(' - ', $item->title);
                $committee_name = trim(preg_replace('|\((.+)\)|', '', $title_parts[0]));
                $meeting_time = end($title_parts);

                /*
                    * Figure out the committee ID.
                    */
                $committee = new Committee();
                $committee->chamber = 'senate';
                $committee->name = $committee_name;
                if ($committee->info() === false) {
                    break(2);
                }
                $committee_id = $committee->id;
            }

            /*
            * Put together our final array of data about this video.
            */
            $video_data =  array(
                'date' => $date,
                'url' => (string) $item->enclosure['url'],
                'chamber' => 'senate',
                'type' => $type
            );

            /*
            * Append committee information.
            */
            if ($type == 'committee') {
                $video_data['committee'] = $committee_name;
                $video_data['committee_id'] = $committee_id;
                $video_data['meeting_time'] = $meeting_time;
            }

            /*
             * Instruct the video process to complete all video processing steps.
             */
            $video_data['step_all'] = true;

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
if (count($videos) == 0) {
    $log->put('No new senate videos found.', 1);
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
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) == 0) {
    $log->put('Could not get a list of existing videos to know if ' . $video['date'] . ', at '
        . $video['url'] . ', is new. Ending.', 5);
    exit(1);
}

$existing_videos = array();
while ($existing_video = mysqli_fetch_assoc($GLOBALS['db'], $result)) {
    $existing_videos[] = $existing_video;
}

foreach ($videos as &$video) {
    /*
    * If we have this same video in the database already, don't save it again.
    */
    foreach ($existing_videos as $existing_video) {
        if (
            ($video['date'] == str_replace('-', '', $existing_video['date']))
            &&
            ($video['chamber'] == $existing_video['chamber'])
            &&
            ($video['type'] == $existing_video['type'])
        ) {
            if ($video['type'] == 'committee') {
                if ($video['committee_id'] == $existing_video['committee_id']) {
                    break(2);
                }
            } else {
                break(2);
            }
        }
    }

    /*
     * Log this to SQS.
     */
    $sqs_client->sendMessage([
        'MessageGroupId'            => '1',
        'MessageDeduplicationId'    => mt_rand(),
        'QueueUrl'                  => 'https://sqs.us-east-1.amazonaws.com/947603853016/rs-video-harvester.fifo',
        'MessageBody'               => json_encode($video)
    ]);

    $log->put('Machine found new ' . ucfirst($video['chamber']) . ' '
        . (!empty($video['committee_id']) ? 'committee' : 'floor')
        . ' video, for ' . $video['date'] . ', ' . ', at: ' . urlencode($video['url']), 5);
}

/*
  * Start up the video-processing EC2 instance.
*/
$ec2_client = new Aws\Ec2\Ec2Client([
    'region'    => 'us-east-1',
    'version'   => '2016-11-15',
    'profile'   => 'default',
    'key'       => AWS_ACCESS_KEY,
    'secret'    => AWS_SECRET_KEY
]);
$action = 'START';
$instanceIds = array('i-076d0d5ee323c4e83');
if ($action == 'START') {
    $result = $ec2_client->startInstances([
        'InstanceIds' => $instanceIds,
    ]);
}

$log->put('Starting video processor.', 5);

/*
 * Write all item GUIDs back to the cache file.
 */
if (file_put_contents($cached_guids, serialize($guids)) === false) {
    $log->put('Could not cache video GUIDs.', 4);
}
