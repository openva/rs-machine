<?php

// Don't bother to run this if the General Assembly isn't in session.
if (IN_SESSION == false) {
    return false;
}

/*
 * Instantiate the logging class
 */
$log = new Log();

$import = new Import($log);

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific page.
$db = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));

// LEGISLATOR ID TRANSLATION
$sql = 'SELECT id, lis_id, chamber
		FROM representatives
		WHERE date_ended IS NULL AND lis_id IS NOT NULL
		ORDER BY id ASC';
$result = $db->query($sql);
if (($result === false) || ($result->rowCount() == 0)) {
    $log->put('No legislators were found in the database, which seems bad.', 10);
    return false;
}
$legislators = array();
while ($legislator = $result->fetch(PDO::FETCH_ASSOC)) {
    $legislators[] = $legislator;
}

# COMMITTEE ID TRANSLATION
$sql = 'SELECT id, lis_id, chamber
		FROM committees
		WHERE parent_id IS NULL
		ORDER BY id ASC';
$result = $db->query($sql);
if (($result === false) || ($result->rowCount() == 0)) {
    $log->put('Error: No committees were found in the database, which seems bad.', 9);
    return false;
}
$committees = array();
while ($committee = $result->fetch(PDO::FETCH_ASSOC)) {
    $committees[] = $committee;
}

# LIST ALL VOTES THAT WE NEED TO RECORD
# Since vote numbers are provided in the bills' history data, we start off by generating a
# list of all votes that are supposed to exist, but we have no record of. Since we don't
# record unrecorded votes (instances of votes in VOTES.CSV for which no vote was recorded),
# many of these instances will be non-recorded votes, but we have code below to skip over
# those. We only include status updates with an lis_vote_id that's eight characters or less,
# because longer ones are for subcommittee votes, which aren't includes in votes.csv.
$empty_votes = array();
$sql = 'SELECT DISTINCT bills_status.lis_vote_id
		FROM bills_status
		LEFT JOIN bills
			ON bills_status.bill_id = bills.id
		WHERE
			(SELECT COUNT(*)
			FROM votes
			WHERE lis_id = bills_status.lis_vote_id
			AND session_id=' . SESSION_ID . ') = 0
		AND bills.session_id = ' . SESSION_ID . ' AND bills_status.lis_vote_id IS NOT NULL
		AND CHAR_LENGTH(bills_status.lis_vote_id) <= 8';
$result = $db->query($sql);
if ($result === false) {
    return false;
} elseif ($result->rowCount() == 0) {
    $log->put('Found no new votes in need of being tallied.', 1);
}
while ($empty_vote = $result->fetch(PDO::FETCH_ASSOC)) {
    $empty_votes[] = $empty_vote['lis_vote_id'];
}

define('API_BASE_URL', 'https://lis.virginia.gov/Vote/api');
define('RATE_LIMIT_SLEEP', 1000);

$headers = [
    'WebAPIKey: ' . LIS_KEY,
    'Content-Type: application/json'
];

$votes = [];

$url = API_BASE_URL . '/GetVoteByIdAsync?sessionCode=20' . SESSION_LIS_ID;
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($http_code != 200) {
    $log->put('Failed to retrieve votes: HTTP ' . $http_code, 4);
    return false;
}

$vote_list = json_decode($response, true);

if (isset($vote_list['Votes'])) {
    $votes = $vote_list['Votes'];
}

if (empty($votes)) {
    $log->put('No votes found for session ' . SESSION_ID, 3);
    return false;
}

// Check if the vote data has changed
$votes_hash = hash('sha256', json_encode($votes));
$votes_file_path = __DIR__ . '/votes.json';
if (file_exists($votes_file_path)) {
    $cached_votes_hash = hash_file('sha256', $votes_file_path);
    if ($votes_hash === $cached_votes_hash) {
        $log->put('Vote data has not changed.', 2);
        return;
    }
}

// Cache the vote file
file_put_contents($votes_file_path, json_encode($votes));

// Iterate through the API response, which is a list of every vote cast so far in this session
foreach ($votes as $vote) {
    // Only bother with votes that we know we are missing (because their identifier is present
    // in bills_status, courtesy of the LIS data, but not in our votes table).
    if (in_array($vote['VoteNumber'], $empty_votes) == false) {
        continue;
    }

    # Get the chamber.
    if ($vote['ChamberCode'] == 'H') {
        $chamber = 'house';
    } elseif ($vote['ChamberCode'] == 'S') {
        $chamber = 'senate';
    }

    // Set default tally values
    $tally = array();
    $tally['Y'] = 0;
    $tally['N'] = 0;
    $tally['X'] = 0;

    // Parse the vote tally string (e.g., "36-Y 1-N 1-X")
    if (preg_match_all('/(\d+)-([YNX])/', $vote['VoteTally'], $matches)) {
        for ($i = 0; $i < count($matches[1]); $i++) {
            $count = $matches[1][$i];
            $type = $matches[2][$i];
            $tally[$type] = (int)$count;
        }
    }

    # Turn the individual counts into a traditional representation of a vote
    # count.
    $final_tally = $tally['Y'] . '-' . $tally['N'];
    if ($tally['X'] > 0) {
        $final_tally .= '-' . $tally['X'];
    }
    $total = $tally['Y'] + $tally['N'] + $tally['X'];

    // This assumption that a simple majority means the bill passed is totally unreasonable
    if ($tally['Y'] > $tally['N']) {
        $outcome = 'pass';
    } else {
        $outcome = 'fail';
    }
    $tally = $final_tally;

    # If there's a committee's LIS ID in the vote prefix then figure out the internal committee ID.
    # But LIS often provides a committee ID for floor votes, for no apparent reason.  For this
    # reason, only assign a committee ID if the total number of votes cast is less than a big chunk
    # of the chamber.
    #
    # Only bother to look up the ID if there are few enough votes that it could plausibly be an
    # in-committee vote.
    if (
        (($chamber == 'senate') && ($total < 25))
        || (($chamber == 'house') && ($total < 80))
    ) {
        $committee_id = $import->lookup_committee_id($committees, $vote['CommitteeNumber']);
    }

    if (!isset($vote['VoteMember']) || count($vote['VoteMember']) < 1) {
        $log->put('No vote members found for vote ID ' . $vote['VoteID'], 2);
        continue;
    }

    # Create a record for this vote.
    $sql = 'INSERT INTO votes
            SET
                lis_id=:lis_id,
                tally=:tally,
                session_id=:session_id,
                total=:total,
                outcome=:outcome,
                chamber=:chamber,
                date_created=now()';
    if (!empty($committee_id)) {
        $sql .= ', committee_id=:committee_id';
    }
    if (!empty($committee_id)) {
        $sql .= ' ON DUPLICATE KEY UPDATE committee_id=:committee_id';
    } else {
        $sql .= ' ON DUPLICATE KEY update total=total';
    }
    $stmt = $db->prepare($sql);
    $session_id = SESSION_ID;  // Create variable to hold session ID
    $stmt->bindParam(':lis_id', $vote['VoteNumber']);
    $stmt->bindParam(':tally', $tally);
    $stmt->bindParam(':session_id', $session_id); // Pass variable reference
    $stmt->bindParam(':total', $total);
    $stmt->bindParam(':outcome', $outcome);
    $stmt->bindParam(':chamber', $chamber);
    if (!empty($committee_id)) {
        $stmt->bindParam(':committee_id', $committee_id);
    }
    $result = $stmt->execute();

    if ($result === false) {
        $log->put('New vote could not be inserted into the database.' . $sql, 9);
    } else {
        # Get the ID for that vote.
        $vote_id = $db->lastInsertId();

        # Iterate through the legislators' votes and insert them.
        if (isset($vote['VoteMember']) && count($vote['VoteMember']) > 0) {
            $sql = 'INSERT INTO representatives_votes
                    SET
                        representative_id=
                            (SELECT id
                            FROM representatives
                            WHERE
                                lis_id=:lis_id AND
                                chamber=:chamber),
                        vote=:vote,
                        vote_id=:vote_id,
                        date_created=now()
                    ON DUPLICATE KEY UPDATE vote=VALUES(vote)';
            $stmt = $db->prepare($sql);
            foreach ($vote['VoteMember'] as $legislator_vote) {
                $member_id = (int)preg_replace('/[^0-9]/', '', $legislator_vote['MemberNumber']);
                $stmt->bindParam(':lis_id', $member_id);
                $stmt->bindParam(':chamber', $chamber);
                $stmt->bindParam(':vote', $legislator_vote['ResponseCode']);
                $stmt->bindParam(':vote_id', $vote_id);
                $result = $stmt->execute();
                if ($result === false) {
                    $log->put('Error: A legislator’s vote could not be inserted into the database.' . $sql, 7);
                }
            }
        }
    }

    # Clear out the variables
    unset($final_tally);
    unset($outcome);
    unset($tally);
    unset($legislator);
    unset($chamber);
    unset($vote_id);
} // end looping the array of votes

# Make sure that no floor votes have wrongly been tallied as committee votes.  This
# is a recurring problem -- somewhere in this file, floor votes are wrongly being
# assigned to committees.  So, just in case, here we update the senate and house
# votes to reassign any committee votes with suspiciously high tallies to the
# floor.
$sql = 'UPDATE votes
		SET committee_id = NULL
		WHERE
            chamber = "senate" AND
            committee_id IS NOT NULL AND
            total > 20';
$db->exec($sql);

$sql = 'UPDATE votes
		SET committee_id = NULL
		WHERE
            chamber="house" AND
            committee_id IS NOT NULL AND
            total > 30';
$db->exec($sql);

# Synchronize the votes table to set the date field to be the same as the date in the
# bills_status table. Otherwise we have to do a join with bills_status every time we want the
# date for a vote, which is a bit silly.
$sql = 'UPDATE votes
		SET date =
			(SELECT DATE_FORMAT(bills_status.date, "%Y-%m-%d") AS date
			FROM bills_status
			LEFT JOIN bills
				ON bills_status.bill_id=bills.id
			WHERE
                bills_status.lis_vote_id = votes.lis_id AND
                bills.session_id=votes.session_id
			LIMIT 1)
		WHERE date IS NULL';
$db->exec($sql);
