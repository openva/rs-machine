<?php

###
# Retrieve and Store the Meeting Schedule
#
# PURPOSE
# Retrieves the CSV file of upcoming meetings, parses it, and stores it in the database.
#
# NOTES
# This has an odd three-step process. First we retrieve the HTML page. Then we use the link to
# the CSV within there to retrieve a dynamic redirect page. Then we use a link within there to
# retrieve the actual CSV. After those steps comes the slicing, dicing, and inserting.
#
# TODO
# Deal with the problem of duplicates that result from events changing after they've been
# syndicated. We have no process for updating existing events, only for adding unrecognized
# ones.
#
###

# Build up an array of committee names and IDs, which we'll use later on to match the calendar
# data.
$sql = 'SELECT c1.id, c1.lis_id, c2.name AS parent, c1.name, c1.chamber
		FROM committees AS c1
		LEFT JOIN committees AS c2
			ON c1.parent_id=c2.id
        WHERE
            c1.date_ended IS NULL AND
            c2.date_ended IS NULL
		ORDER BY c1.chamber, c2.name, c1.name';
$stmt = $GLOBALS['dbh']->prepare($sql);
$stmt->execute();
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($result) == 0) {
    $log->put('No subcommittees were found, which seems bad.', 7);
    return false;
}

$committees = array();

foreach ($result as $committee) {

    // If this is a subcommittee, shuffle around the array keys.
    if (!empty($committee['parent'])) {
        $committee['sub'] = $committee['name'];
        $committee['name'] = $committee['parent'];
        unset($committee['parent']);
    }

    // If it's not a subcommittee, modify the LIS ID to adhere to the LIS format
    else {
        $prefix = ($committee['chamber'] == 'house') ? 'H' : 'S';
        $committee['lis_id'] = $prefix . str_pad($committee['lis_id'], 2, '0', STR_PAD_LEFT);
    }

    // Start the plain text description that we'll use to try to match the meeting description.
    $tmp = ucfirst($committee['chamber']) . ' ' . $committee['name'];

    # If this is a subcommittee, then we have to deal with a series of naming possibilities,
    # since legislative staff are hugely inconsistent in their naming practices. Any of the
    # following is viable:
    # Senate Finance Education Subcommittee
    # Senate Finance - Education
    # Senate Finance - Subcommittee Education
    if (!empty($committee['sub'])) {
        $committees[] = array($committee['id'] => $tmp . ' - ' . $committee['sub']);
        $committees[] = array($committee['id'] => $tmp . ' - Subcommittee ' . $committee['sub']);
        $committees[] = array($committee['id'] => $tmp . ' ' . $committee['sub'] . ' Subcommittee');

        # If the word "and" is used in this subcommittee name, then we need to also create
        # versions of it with an ampersand in place of the word "and," because LIS can't decide
        # which they want to use to name committees.
        if (stristr($committee['sub'], ' and ') != false) {
            $committee['sub'] = str_replace(' and ', ' & ', $committee['sub']);
            $committees[] = array($committee['id'] => $tmp . ' - ' . $committee['sub']);
            $committees[] = array($committee['id'] => $tmp . ' - Subcommittee ' . $committee['sub']);
            $committees[] = array($committee['id'] => $tmp . ' ' . $committee['sub'] . ' Subcommittee');
        }
    } else {
        $committees[] = array($committee['id'] => $tmp);
        if (stristr($tmp, ' and ') != false) {
            $tmp = str_replace(' and ', ' & ', $tmp);
            $committees[] = array($committee['id'] => $tmp);
        }
    }

    unset($tmp);
}

# And build up a listing of all meetings being held after now, which we'll use below to avoid
# making duplicate additions.
// Since we're separating out the date and time fields, it's not clear to me how "now" is
// established. Maybe we just select everything from today on, and filter out the gone-by events
// in the PHP?
$sql = 'SELECT committee_id, date, time, timedesc, description, location
		FROM meetings
		WHERE session_id = :session_id AND date >= NOW()';
$stmt = $GLOBALS['dbh']->prepare($sql);
$stmt->execute([':session_id' => SESSION_ID]);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($result) > 0) {
    $upcoming = $result;
}

# Retrieve the meeting schedule from the API
$apiUrl = 'https://lis.virginia.gov/Schedule/api/GetScheduleListAsync';

$headers = [
    "Content-Type: application/json",
    'WebAPIKey: ' . LIS_KEY
];

$ch = curl_init();

// Set the URL and other options
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute the request
$response = curl_exec($ch);

// Get the HTTP status code
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode != '200') {
    $log->put('Error: LIS’s GetScheduleListAsync returned an HTTP ' . $httpCode
        . '—meeting schedule could not be updated.', 6);
    return false;
}

// Check for errors
if ($response === false) {
    $error = curl_error($ch);
    $log->put('Error: The cURL query to LIS’s GetScheduleListAsync failed with “' . $error
        . '”—meeting schedule could not be updated.', 6);
    return false;
}

if ($response === false) {
    $log->put('Error: LIS’s GetScheduleListAsync returned an invalid response.', 6);
    return false;
}

$meetings = json_decode($response, true);

if (empty($meetings['Schedules'])) {
    $log->put('No meetings found in the API response.', 7);
    return false;
}

// Iterate through the meetings from the API response
foreach ($meetings['Schedules'] as $meeting) {
    $meeting['date'] = $meeting['ScheduleDate'];
    if (!empty($meeting['ScheduleTime'])) {
        $meeting['time'] = $meeting['ScheduleTime'];
    } elseif (!empty($meeting['Description'])) {
        $meeting['description_raw'] = $meeting['Description'];
    }
    if (!empty($meeting['CommitteeNumber'])) {
        $meeting['committee_lis_id'] = $meeting['CommitteeNumber'];
    }
    $meeting['description'] = $meeting['OwnerName'] . ' ' . $meeting['ScheduleType'];
    $meeting['location'] = $meeting['RoomDescription'];

    // Skip any meeting for which required fields are empty
    if (empty($meeting['date']) || empty($meeting['description']) || empty($meeting['location'])) {
        continue;
    }

    // Determine which chamber that this meeting is for
    if (preg_match('/house/i', $meeting['description_raw']) === 1) {
        $meeting['chamber'] = 'house';
    } elseif (preg_match('/senate/i', $meeting['description_raw']) === 1) {
        $meeting['chamber'] = 'senate';
    } else {
        continue;
    }

    // If a committee isn't meeting, or it's a chamber adjourning, then ignore it
    if (
            stristr($meeting['description_raw'], 'not meeting')
            || stristr($meeting['description_raw'], 'Senate Adjourned')
            || stristr($meeting['description_raw'], 'House Adjourned')
    ) {
        continue;
    }

    // If an approximate time is listed (something like "1/2 hr aft" or "TBA"), then we've got to
    // a) turn it into plain English and b) ignore the claimed time.
    if (
            stristr($meeting['description_raw'], '1/2 hr aft')
            ||
            stristr($meeting['description_raw'], '1/2 hour after')
    ) {
        $meeting['timedesc'] = 'Half an hour after the ' . ucfirst($meeting['chamber'])
            . ' adjourns';
        unset($meeting['time']);
    } elseif (
            stristr($meeting['description_raw'], '15 min aft')
            ||
            stristr($meeting['description_raw'], '15 minutes after')
    ) {
        $meeting['timedesc'] = 'Fifteen minutes after the ' . ucfirst($meeting['chamber'])
            . ' adjourns';
        unset($meeting['time']);
    } elseif (stristr($meeting['description_raw'], '1 hour after')) {
        $meeting['timedesc'] = 'One hour after the ' . ucfirst($meeting['chamber'])
            . ' adjourns';
        unset($meeting['time']);
    } elseif (stristr($meeting['description_raw'], '1 and 1/2 hours after')) {
        $meeting['timedesc'] = 'An hour and a half after the ' . ucfirst($meeting['chamber'])
            . ' adjourns';
        unset($meeting['time']);
    } elseif (stristr($meeting['description_raw'], '1/2 hour before Session')) {
        $meeting['timedesc'] = 'Half an hour before the ' . ucfirst($meeting['chamber'])
            . ' convenes';
        unset($meeting['time']);
    } elseif (stristr($meeting['description_raw'], 'TBA')) {
        $meeting['timedesc'] = 'To be announced';
        unset($meeting['time']);
    }

    // If the time is approximate, then we want to establish a meeting date. (But not a time.)
    if (isset($meeting['timedesc'])) {
        // Establish a meeting date.
        $meeting['datetime'] = strtotime('00:00:00 ' . $meeting['date']);
        $meeting['date'] = date('Y-m-d', $meeting['datetime']);
        unset($meeting['time']);
        unset($meeting['datetime']);
    }

    // But if we've got a trustworthy time, format the date and the time properly.
    else {
        // Convert the date and time into a timestamp.
        $meeting['datetime'] = strtotime($meeting['time'] . ' ' . $meeting['date']);
        $meeting['date'] = date('Y-m-d', $meeting['datetime']);
        $meeting['time'] = date('H:i', $meeting['datetime']) . ':00';
        unset($meeting['datetime']);
    }

    // Attempt to match the committee with a known committee. Start by stepping through every
    // committee.
    for ($i = 0; $i < count($committees); $i++) {
        // Since each committee can have multiple names, we now step through each name for this
        // committee and try to match it.
        foreach ($committees[$i] as $id => $committee) {
            if (stristr($meeting['description'], $committee) != false) {
                if (is_numeric($id)) {
                    $meeting['committee_id'] = $id;
                    break;
                }
            }
        }
    }

    // check to see if we already know about this meeting by comparing it to data in the DB

    // If this meeting has gone by, then ignore it
    if (isset($meeting['time'])) {
        $tmp = strtotime($meeting['date'] . ' ' . $meeting['time']);
    } else {
        $tmp = strtotime($meeting['date'] . ' 00:00:00');
    }
    if ($tmp < time()) {
        continue;
    }

    // If we've already got a record of this meeting then ignore it
    if (!empty($upcoming)) {
        foreach ($upcoming as $known) {
            if (
                    ($meeting['date'] == $known['date'])
                    &&
                    ($meeting['description'] == $known['description'])
                    &&
                    (
                        ($meeting['location'] == $known['location'])
                        ||
                        ($meeting['committee_id'] == $known['committee_id'])
                    )
                    &&
                    (
                        ($meeting['time'] == $known['time'])
                        ||
                        ($meeting['timedesc'] == $known['timedesc'])
                    )
            ) {
                $duplicate = true;
                break;
            }
        }
        if ($duplicate == true) {
            unset($duplicate);
            continue;
        }
    }

    // Prepare and insert the data into the DB using prepared statements
    $sql = 'INSERT INTO meetings
            SET
                date = :date,
                description = :description,
                session_id = :session_id,
                location = :location,
                date_created = NOW()';

    $params = [
        ':date' => $meeting['date'],
        ':description' => $meeting['description'],
        ':session_id' => SESSION_ID,
        ':location' => $meeting['location']
    ];

    if (!empty($meeting['time'])) {
        $sql .= ', time = :time';
        $params[':time'] = $meeting['time'];
    }
    if (!empty($meeting['timedesc'])) {
        $sql .= ', timedesc = :timedesc';
        $params[':timedesc'] = $meeting['timedesc'];
    }
    if (!empty($meeting['committee_id'])) {
        $sql .= ', committee_id = :committee_id';
        $params[':committee_id'] = $meeting['committee_id'];
    }

    $stmt = $GLOBALS['dbh']->prepare($sql);
    $result = $stmt->execute($params);

    if (!$result) {
        $errorInfo = $stmt->errorInfo();
        $log->put('Failed to add meeting ' . $meeting['description'] . ' on ' . $meeting['date']
            . '. Error: ' . $errorInfo[2], 5);
    } else {
        $log->put('Added meeting ' . $meeting['description'] . ' on ' . $meeting['date'] . '.', 1);
    }
}

// Delete all of the duplicate meetings. We end up with the same meeting recorded over and over
// again, and that's most easily dealt with by simply deleting them after they're inserted.
$sql = 'DELETE m1
		FROM meetings m1, meetings m2
		WHERE
            m1.date = m2.date AND
            m1.time = m2.time AND
            m1.location = m2.location AND
		    m1.description = m2.description AND
            m1.id < m2.id';
$GLOBALS['dbh']->exec($sql);
