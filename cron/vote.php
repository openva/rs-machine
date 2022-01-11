<?php

/*
 * TO DO
 * It's swell that we've moved this to use PDO-based queries, but we still need to move to prepared
 * queries for the two update loops.
 */

# Don't bother to run this if the General Assembly isn't in session.
if (IN_SESSION == 'n')
{
	return FALSE;
}

# Give this script 60 seconds to complete.
set_time_limit(240);

# FUNDAMENTAL VARIABLES
$session_id = SESSION_ID;
$session_year = SESSION_YEAR;
$dlas_session_id = SESSION_LIS_ID;

$import = new Import();

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific
# page.
$db = new PDO( PDO_DSN, PDO_USERNAME, PDO_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT) );

# LEGISLATOR ID TRANSLATION
$sql = 'SELECT id, lis_id, chamber
		FROM representatives
		WHERE date_ended IS NULL AND lis_id IS NOT NULL
		ORDER BY id ASC';
$result = $db->query($sql);
if ( ($result === FALSE) || ($result->rowCount() == 0) )
{
	$log->put('No legislators were found in the database, which seems bad.', 10);
	return FALSE;
}
$legislators = array();
while ($legislator = $result->fetch(PDO::FETCH_ASSOC))
{
	$legislators[] = $legislator;
}

# COMMITTEE ID TRANSLATION
$sql = 'SELECT id, lis_id, chamber
		FROM committees
		WHERE parent_id IS NULL
		ORDER BY id ASC';
$result = $db->query($sql);
if ( ($result === FALSE) || ($result->rowCount() == 0) )
{
	$log->put('No committees were found in the database, which seems bad.', 9);
	return FALSE;
}
$committees = array();
while ($committee = $result->fetch(PDO::FETCH_ASSOC))
{
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
			AND session_id=' . $session_id . ') = 0
		AND bills.session_id = ' . $session_id . ' AND bills_status.lis_vote_id IS NOT NULL
		AND CHAR_LENGTH(bills_status.lis_vote_id) <= 8';
		//AND DATEDIFF(now(), bills_status.date) <= 2';
$result = $db->query($sql);
if ($result === FALSE)
{
	return FALSE;
}
elseif ($result->rowCount() == 0)
{
	$log->put('Found no new votes in need of being tallied.', 1);
}
while ($empty_vote = $result->fetch(PDO::FETCH_ASSOC))
{
	$empty_votes[] = $empty_vote['lis_vote_id'];
}

# Retrieve the CSV data and save it to a local file.
$vote = get_content('sftp://' . LIS_FTP_USERNAME . ':' . LIS_FTP_PASSWORD . '@sftp.dlas.virginia.gov/CSV'
	. $dlas_session_id . '/csv'. $dlas_session_id .'/VOTE.CSV');

if ($vote === FALSE)
{
	$log->put('vote.csv couldn’t be retrieved from sftp.dlas.virginia.gov.', 8);
	return FALSE;
}

# If the MD5 value of the new file is the same as the saved file, then there's nothing to update.
if (file_exists(__DIR__ . '/vote.csv'))
{
	if (md5($vote) == md5_file(__DIR__ . '/vote.csv'))
	{
		$log->put('vote.csv was unchanged since the last update.', 2);
		return FALSE;
	}
}

if (!file_put_contents(__DIR__ . '/vote.csv', $vote))
{
	$log->put('Could not save vote.csv.', 9);
	return FALSE;
}

# Open the resulting file.
$fp = fopen(__DIR__ . '/vote.csv','r');

# Step through each row in the CSV file, one by one.
while (($vote = fgetcsv($fp, 2500, ',')) !== FALSE)
{
	# Only deal with votes that were tallied -- that is, that have row counts greater than 1.
	if (count($vote) > 1)
	{
		# If our list of votes for which we have no record doesn't contain this vote, then
		# skip to the next line in the CSV file.
		if (in_array($vote[0], $empty_votes))
		{
			# Buld up an array of votes.
			$votes[] = $vote;
		}
	}
}

# Close the CSV file.
fclose($fp);

# We no longer need our array of empty votes.
unset($empty_votes);

if (!isset($votes) || count($votes) == 0)
{
	$log->put('vote.csv contained no votes.', 3);
	return FALSE;
}

foreach ($votes as $vote)
{

	# Get the LIS vote ID.
	$lis_vote_id = $vote[0];

	# Get the chamber.
	if ($lis_vote_id{0} == 'H')
	{
		$chamber = 'house';
	}
	elseif ($lis_vote_id{0} == 'S')
	{
		$chamber = 'senate';
	}

	# Set some default variables.
	$tally['Y'] = 0;
	$tally['N'] = 0;
	$tally['X'] = 0;

	# Iterate through the votes cast on this one bill.
	for ($i=1; $i<count($vote); $i++)
	{
		if (($i % 2) == 1)
		{
			$legislator[$i]['id'] = $import->lookup_legislator_id($legislators, $vote[$i]);
		}
		elseif (($i % 2) == 0)
		{
			$legislator[$i-1]['vote'] = $vote[$i];
			if ($vote[$i] == 'Y')
			{
				$tally['Y']++;
			}
			elseif ($vote[$i] == 'N')
			{
				$tally['N']++;
			}
			elseif ($vote[$i] == 'X')
			{
				$tally['X']++;
			}
		}
	}

	# Turn the individual counts into a traditional representation of a vote
	# count.
	$final_tally = $tally['Y'].'-'.$tally['N'];
	if ($tally['X'] > 0)
	{
		$final_tally .= '-'.$tally['X'];
	}
	$total = $tally['Y'] + $tally['N'] + $tally['X'];

	// This assumption that a simple majority means the bill passed is totally unreasonable.
	if ($tally['Y'] > $tally['N'])
	{
		$outcome = 'pass';
	}
	else
	{
		$outcome = 'fail';
	}
	$tally = $final_tally;

	$vote_prefix = substr($lis_vote_id, 0, 3);

	# If there's a committee's LIS ID in the vote prefix then figure out
	# the internal committee ID.  But LIS often provides a committee ID
	# for floor votes, for no apparent reason.  For this reason, only assign
	# a committee ID if the total number of votes cast is less than a big
	# chunk of the chamber.
	if (preg_match('/^([h-s]{1})([0-9]{2})/Di', $vote_prefix, $regs))
	{
		# Only bother to look up the ID if there are few enough votes that it could
		# plausibly be an in-committee vote.
		if ((($chamber == 'senate') && ($total < 25))
			|| (($chamber == 'house') && ($total < 80)))
		{
			$committee_id = $import->lookup_committee_id($committees, $vote_prefix);
		}
	}

	# Create a record for this vote.
	$sql = 'INSERT INTO votes
			SET lis_id="' . $lis_vote_id . '", tally="' . $tally . '", session_id="' . $session_id . '",
			total=' . $total . ', outcome="' . $outcome . '", chamber="' . $chamber . '",
			date_created=now()';
	if (!empty($committee_id))
	{
		$sql .= ', committee_id=' . $committee_id;
	}
	if (!empty($committee_id))
	{
		$sql .= ' ON DUPLICATE KEY UPDATE committee_id=' . $committee_id;
	}
	else
	{
		$sql .= ' ON DUPLICATE KEY update total=total';
	}
	$result = $db->exec($sql);

	if ($result === FALSE)
	{
		$log->put('New vote could not be inserted into the database.' . $sql, 9);
	}

	else
	{

		# Get the ID for that vote.
		$vote_id = $db->lastInsertID();

		# Iterate through the legislators' votes and insert them after reindexing the array.
		$legislator = array_values($legislator);
		for ($i=0; $i<count($legislator); $i++)
		{
			if (!empty($legislator[$i]['id']) && !empty($legislator[$i]['vote']))
			{

				# Convert blank votes into the abstensions that they represent.
				if ($legislator[$i]['vote'] == ' ')
				{
					$legislator[$i]['vote'] = 'A';
				}

				$sql = 'INSERT DELAYED INTO representatives_votes
						SET representative_id=' . $legislator[$i]['id'] . ',
						vote="' . $legislator[$i]['vote'] . '", vote_id=' . $vote_id . ',
						date_created=now()
						ON DUPLICATE KEY UPDATE vote=vote';
				$result = $db->exec($sql);
				if ($result === FALSE)
				{
					echo '<p>Insertion of vote record failed: <code>' . $sql . '</code></p>';
					$log->put('A legislator’s vote could not be inserted into the database.' . $sql, 7);
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
		WHERE chamber = "senate" AND committee_id IS NOT NULL AND total > 20';
$db->exec($sql);

$sql = 'UPDATE votes
		SET committee_id = NULL
		WHERE chamber="house" AND committee_id IS NOT NULL AND total > 30';
$db->exec($sql);

# Synchronize the votes table to set the date field to be the same as the date in the
# bills_status table. Otherwise we have to do a join with bills_status every time we want the
# date for a vote, which is a bit silly.
$sql = 'UPDATE votes
		SET date =
			(SELECT bills_status.date
			FROM bills_status
			LEFT JOIN bills
				ON bills_status.bill_id=bills.id
			WHERE bills_status.lis_vote_id = votes.lis_id
			AND bills.session_id=votes.session_id
			LIMIT 1)
		WHERE date IS NULL';
$db->exec($sql);
