<?php

###
# Retrieve and Store the Meeting Schedule
#
# PURPOSE
# Retrieves the schedule of meetings from LIS's API, and makes the meetings stored for today and
# later match those of the House, the Senate, and their committees in it: adding new ones, and
# removing any that LIS has cancelled, rescheduled, or listed twice.
#
# NOTES
# Which meetings are kept, and how they're stored, is up to RsMachine\Meetings.
#
###

use RsMachine\Meetings;
use RsMachine\TaskResult;

# The committees, to match the schedule's meetings to.
$sql = 'SELECT c1.id, c1.lis_id, c1.chamber, c1.name, c2.name AS parent
        FROM committees AS c1
        LEFT JOIN committees AS c2
            ON c1.parent_id = c2.id
        WHERE
            c1.date_ended IS NULL AND
            c2.date_ended IS NULL';
$stmt = $GLOBALS['dbh']->prepare($sql);
$stmt->execute();
$committees = Meetings::committeeIndex($stmt->fetchAll(PDO::FETCH_ASSOC));

if (count($committees['numbers']) == 0) {
    return TaskResult::failed('no committees were found, so meetings could not be matched to them');
}

# Retrieve the meeting schedule from the API.
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://lis.virginia.gov/Schedule/api/GetScheduleListAsync');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'WebAPIKey: ' . LIS_KEY,
]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
$response = curl_exec($ch);

if ($response === false) {
    return TaskResult::failed('the request to LIS’s GetScheduleListAsync failed with “'
        . curl_error($ch) . '”, so the meeting schedule could not be updated');
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($http_code != 200) {
    return TaskResult::failed('LIS’s GetScheduleListAsync returned an HTTP ' . $http_code
        . ', so the meeting schedule could not be updated');
}

$schedule = json_decode($response, true);
if (!is_array($schedule) || !isset($schedule['Schedules']) || !is_array($schedule['Schedules'])) {
    return TaskResult::failed('LIS’s GetScheduleListAsync returned something other than a '
        . 'schedule, so the meeting schedule could not be updated');
}

# An empty schedule is more likely a fault at LIS than the end of all meetings, so the stored
# meetings are left alone.
if (count($schedule['Schedules']) == 0) {
    return TaskResult::skipped('LIS’s schedule lists no meetings');
}

$scheduled = [];
foreach ($schedule['Schedules'] as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $meeting = Meetings::fromSchedule($entry, $committees);
    if ($meeting !== null) {
        $scheduled[] = $meeting;
    }
}

$today = (new DateTimeImmutable('now', new DateTimeZone(Meetings::TIMEZONE)))->format('Y-m-d');

$stmt = $GLOBALS['dbh']->prepare('SELECT id, committee_id, date, time, timedesc, description, location
    FROM meetings
    WHERE date >= :today');
$stmt->execute([':today' => $today]);
$stored = $stmt->fetchAll(PDO::FETCH_ASSOC);

$changes = Meetings::changes($scheduled, $stored, $today);

$insert = $GLOBALS['dbh']->prepare('INSERT INTO meetings
    SET
        date = :date,
        time = :time,
        timedesc = :timedesc,
        description = :description,
        location = :location,
        committee_id = :committee_id,
        session_id = :session_id,
        date_created = NOW()');
$delete = $GLOBALS['dbh']->prepare('DELETE FROM meetings WHERE id = :id');

$GLOBALS['dbh']->beginTransaction();
try {
    foreach ($changes['delete'] as $id) {
        $delete->execute([':id' => $id]);
    }
    foreach ($changes['insert'] as $meeting) {
        $insert->execute([
            ':date'         => $meeting['date'],
            ':time'         => $meeting['time'],
            ':timedesc'     => $meeting['timedesc'],
            ':description'  => $meeting['description'],
            ':location'     => $meeting['location'],
            ':committee_id' => $meeting['committee_id'],
            ':session_id'   => SESSION_ID,
        ]);
        $log->put('Added meeting ' . $meeting['description'] . ' on ' . $meeting['date'] . '.', 1);
    }
    $GLOBALS['dbh']->commit();
} catch (Throwable $e) {
    $GLOBALS['dbh']->rollBack();
    return TaskResult::failed('the meeting schedule could not be saved: ' . $e->getMessage());
}

return TaskResult::completed([
    'upcoming' => count($stored) - count($changes['delete']) + count($changes['insert']),
    'added'    => count($changes['insert']),
    'removed'  => count($changes['delete']),
]);
