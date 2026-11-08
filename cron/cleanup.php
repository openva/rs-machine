<?php

###
# MISC. DATA CLEAN UP FUNCTIONS
#
# PURPOSE
# Does all of the nice conversions of data that make Richmond Sunlight something other than a
# parroting of LIS data.
#
###

/*
 * mysqli reports an error by returning false, without throwing, so each of this file's mysqli
 * statements goes through $query, which logs any error with the start of the statement. A failed
 * statement doesn't stop the rest of the cleanup, but the task reports that it failed.
 *
 * MySQL closes a connection that's been idle for wait_timeout (300 seconds in production), and
 * this file's first steps use PDO, so the mysqli connection can be closed by the time it's used.
 * When it has been (errors 2006 and 2013), $query reconnects and tries the statement again.
 *
 * A statement that takes more than 30 seconds is logged, since this whole file usually takes less.
 */
$failed_statements = 0;
$query = static function (string $sql) use ($log, &$failed_statements) {
    $start = microtime(true);
    $result = mysqli_query($GLOBALS['db'], $sql);
    if ($result === false && in_array(mysqli_errno($GLOBALS['db']), [2006, 2013], true)) {
        try {
            $db = @mysqli_connect(PDO_SERVER, PDO_USERNAME, PDO_PASSWORD, MYSQL_DATABASE);
        } catch (mysqli_sql_exception $e) {
            $db = false;
        }
        if ($db instanceof mysqli) {
            mysqli_query($db, 'SET NAMES "utf8"');
            $GLOBALS['db'] = $db;
            $log->put('Cleanup reconnected to the database via mysqli.', 3);
            $result = mysqli_query($db, $sql);
        }
    }
    if ($result === false) {
        $failed_statements++;
        $log->put('Cleanup statement failed: ' . mysqli_error($GLOBALS['db']) . ' ('
            . mysqli_errno($GLOBALS['db']) . '), in: '
            . mb_substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 150), 5);
    }
    $seconds = microtime(true) - $start;
    if ($seconds > 30) {
        $log->put('Cleanup statement took ' . round($seconds) . 's: '
            . mb_substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 150), 4);
    }
    return $result;
};


###
# FILL IN BILLS' INTRODUCTION DATE
# bills.php stores the date that LIS's BILLS.CSV gives. For a bill that it gives none, use the
# date of the bill's earliest history entry.
###
$sql = $dbh->prepare('UPDATE bills
						SET date_introduced =
							(SELECT date
							FROM bills_status
							WHERE bills_status.bill_id = bills.id
							ORDER BY date ASC
							LIMIT 1)
						WHERE session_id= :session_id
						AND date_introduced IS NULL');
$sql->bindParam(':session_id', $session_id);
$result = $sql->execute();

###
# AS A LAST RESORT, USE THE DATE THE BILL WAS ADDED
# Much of the site depends on having an introduction date, so a bill with neither a date from LIS
# nor any history yet gets the date we first saw it.
###
$sql = $dbh->prepare('UPDATE bills
						SET date_introduced = DATE_FORMAT( date_created, "%Y-%m-%d" )
						WHERE date_introduced IS NULL
						AND session_id= :session_id');
$sql->bindParam(':session_id', $session_id);
$result = $sql->execute();

###
# UPDATE BILLS' STATUS
# Perform a series of queries to provide an indication of whether the bill passed
# or failed in committee.  The only purpose of this is to determine whether to
# check off the box next to "Passed Committee" in the "how a bill becomes law"
# series on every bill's page.
###

// Translate LIS's history entries into our statuses, and update bills' statuses to match. The
// rules are in RsMachine\StatusTranslation.
$start = microtime(true);
\RsMachine\StatusTranslation::apply($dbh, (int) $session_id);
if (microtime(true) - $start > 30) {
    $log->put('Cleanup took ' . round(microtime(true) - $start) . 's to translate statuses.', 4);
}

###
# UPDATE BILLS' STATUS
# Newly-introduced bills are, oddly, not having their status updated.
# Set them to the status of "introduced."
###
$sql = 'UPDATE bills
		SET status="introduced"
		WHERE
			(status IS NULL OR status="") AND
			session_id=' . $session_id;
$result = $query($sql);

###
# UPDATE BILLS' OUTCOME FLAG
# Every bill has a passed/failed flag, used for sweeping DB queries where we only want bills that
# have passed (or, I suppose, that have failed).
###
$sql = 'UPDATE bills
		SET outcome="failed"
		WHERE (status="failed committee" OR status="failed" OR status="failed house"
			OR status="failed senate" OR status="vetoed by governor" OR status="stricken")
		AND (outcome IS NULL OR outcome="passed") AND session_id=' . $session_id;
$query($sql);

$sql = 'UPDATE bills
		SET outcome="passed"
		WHERE (status="signed by governor" OR status="enacted") AND
        (outcome IS NULL OR outcome="failed") AND
        session_id=' . $session_id;
$query($sql);

$sql = 'UPDATE bills
		SET outcome = NULL
		WHERE
            outcome="failed" AND
            status != "failed committee" AND
            status != "failed" AND
		    status != "failed house" AND
            status != "failed senate" AND
            status != "vetoed by governor" AND
            status != "stricken" AND
            status != "signed by governor" AND
            status != "enacted" AND
            session_id=' . $session_id;
$query($sql);

// Mark bills as failed if their status is still "introduced" after the session is over. This acts
// on the session before the one being run, so it's skipped when running against another session
// (run.php --session), where that could be any session at all.
if (!defined('SESSION_OVERRIDE') && IN_SESSION == false && time() > strtotime(SESSION_END)) {
    $sql = 'UPDATE bills
            SET outcome="failed"
            WHERE status="introduced" AND session_id = ' . ($session_id - 1);
    $query($sql);
}

###
# UPDATE BILLS' FULL TEXT WHERE IT'S CURRENTLY BLANK
# Synchronize the bills table's bill text with the text stored in the bills_full_text table.
# The latter stores the latest text but, for convenience of querying, we sync it here.
###
$sql = 'UPDATE bills
		SET full_text =
			(SELECT text
			FROM bills_full_text
			WHERE
                bill_id = bills.id AND
                text IS NOT NULL
			ORDER BY date_introduced DESC
			LIMIT 1)
		WHERE session_id=' . $session_id . ' AND full_text IS NULL';
$query($sql);

###
# PERIODICALLY SYNCHRONIZE BILLS' FULL TEXT
# Synchronize the bills table's bill text with the text stored in the bills_full_text table.
# The latter stores the latest text but, for convenience of querying, we sync it here. This is
# simply because the full text changes periodically, and we want to make sure that we're working
# with the most recent version.
###
$sql = 'UPDATE bills
		SET full_text =
			(SELECT text
			FROM bills_full_text
			WHERE bill_id = bills.id
			ORDER BY date_introduced DESC
			LIMIT 1)
		WHERE session_id=' . $session_id . '
		ORDER BY RAND()
		LIMIT 50';
$query($sql);

###
# DEAL WITH MISSING FULL TEXT RECORDS
# The only way we know to retrieve bills' full text is if there's an entry in bills_full_text that
# is missing the full text. In the rare circumstance that this happens, recreate the entry in the
# bills_full_text table.
$sql = 'INSERT INTO
            bills_full_text (bill_id, session_id, number, date_introduced, date_created)
            SELECT bills.id, bills.session_id, bills.number, bills.date_introduced, NOW()
        FROM bills
        LEFT JOIN bills_full_text
            ON bills.id = bills_full_text.bill_id
        WHERE
            bills_full_text.bill_id IS NULL AND
            bills.date_introduced IS NOT NULL';
$query($sql);

###
# CLEAN UP STRAY CONTROL CHARACTERS
# From the process of converting from the Western to UTF-8 character set (leg1 stores data in a
# Western mish-mash), some goofy characters tend to show up.
###
$sql = 'UPDATE bills
		SET summary = REPLACE(summary, "Â", "")
		WHERE session_id=' . $session_id;
$query($sql);

###
# UPDATE BILLS' SUMMARY HASHES
# We maintain an MD5 hash of all bill summaries to allow a quick discovery of bills that are
# identical to the current one. A bill gets a new summary as it advances, so the hash is updated
# whenever it no longer matches. (summary_hash is latin1, so MD5()'s result is converted to
# compare them; <=> treats a missing hash of a missing summary as matching.)
###
$sql = 'UPDATE bills
		SET summary_hash = MD5(summary)
		WHERE
			NOT (summary_hash <=> CONVERT(MD5(summary) USING latin1)) AND
			session_id = ' . $session_id;
$query($sql);

###
# MARK BILLS AS INCORPORATED INTO ANOTHER BILL
# Sometimes a bill is absorbed into another bill, generally in committee. When that happens we want
# to record the ID of the target bill in the source bill. For instance, if HB1 is incorporated into
# HB2, then we record HB2's ID (an integer) in the incorporated_into column for the HB1 record.
###
$sql = 'SELECT id, session_id, (
			SELECT status
			FROM bills_status
			WHERE bill_id = bills.id
			AND translation = "incorporated"
			LIMIT 1
			) AS incorporated
		FROM bills
		WHERE
			incorporated_into IS NULL AND
			session_id = ' . $session_id . '
		HAVING incorporated IS NOT NULL';
$result = $query($sql);
$find_target = $dbh->prepare('SELECT id FROM bills WHERE number = :number AND session_id = :session_id');
$record_target = $dbh->prepare('UPDATE bills SET incorporated_into = :target WHERE id = :id');
while ($result !== false && ($bill = mysqli_fetch_array($result))) {
    # The bill that this one was incorporated into, named in the history entry, e.g.
    # "Incorporated by Finance (HB979-Watts)".
    $target_number = \RsMachine\StatusTranslation::incorporatedInto((string) $bill['incorporated']);
    if ($target_number === null) {
        continue;
    }

    $find_target->execute([':number' => $target_number, ':session_id' => $bill['session_id']]);
    $target_id = $find_target->fetchColumn();
    if ($target_id !== false && (int) $target_id !== (int) $bill['id']) {
        $record_target->execute([':target' => $target_id, ':id' => $bill['id']]);
    }
}

###
# SYNCHRONIZE IDENTICAL BILLS' TAGS
# When two or more bills have the same summary, they're identical, so they get each other's tags.
# (Bills without a summary share the hash of nothing, so they're left out.) RsMachine\TagSync says
# which tags each lacks.
###
$sql = 'SELECT bills.summary_hash AS hash, bills.id,
			GROUP_CONCAT(DISTINCT tags.tag ORDER BY tags.tag SEPARATOR "\t") AS tags
		FROM bills
		LEFT JOIN tags
			ON bills.id = tags.bill_id
		WHERE
			bills.session_id = ' . $session_id . '
			AND bills.summary IS NOT NULL AND bills.summary != ""
			AND EXISTS (
				SELECT 1 FROM bills AS bills2
				WHERE bills2.summary_hash = bills.summary_hash
					AND bills2.session_id = bills.session_id
					AND bills2.id != bills.id)
		GROUP BY bills.id';
$result = $query($sql);
if ($result !== false) {
    $identical = [];
    while ($bill = mysqli_fetch_assoc($result)) {
        $identical[$bill['hash']][(int) $bill['id']] = ($bill['tags'] ?? '') === ''
            ? []
            : explode("\t", $bill['tags']);
    }

    foreach ($identical as $bills) {
        foreach (\RsMachine\TagSync::missing($bills) as $bill_id => $tags) {
            foreach ($tags as $tag) {
                // The tags table expects a user ID, which this has none of, so it's given 0.
                $query('INSERT INTO tags
                        SET bill_id = ' . (int) $bill_id . ',
                            tag = "' . mysqli_real_escape_string($GLOBALS['db'], $tag) . '",
                            user_id = 0, date_created = NOW()');
            }
        }
    }
}

// We wind up with bills tagged "Virginia." This is not useful.
$sql = 'DELETE
        FROM tags
        WHERE tag="virginia"';
$query($sql);

###
# UPDATE BILLS' HOTNESS RANKING
# Each bill is ranked by how "hot" it is. This is a metric for how interested that people are in
# a given bill. When the legislature is in session, we base this on the past day's activity. When
# it's not in session, we based it on the past three days. The formula itself is based on five
# factors: comments, views, Photosynthesis adds, poll votes, and subscriptions to comments.
###
if (LEGISLATIVE_SEASON == true) {
    $days = 1;
} else {
    $days = 3;
}
$sql = 'UPDATE bills
		SET hotness=
			(
				(SELECT COUNT(*)
				FROM comments
				WHERE bill_id=bills.id AND (DATEDIFF(now(), date_created) <= ' . $days . ')) * 5
				+
				(SELECT COUNT(DISTINCT ip)
				FROM bills_views
				WHERE bill_id=bills.id AND (DATEDIFF(now(), date) <= ' . $days . ')) / 3
				+
				(SELECT COUNT(*)
				FROM dashboard_bills
				WHERE bill_id=bills.id AND (DATEDIFF(now(), date_created) <= ' . $days . ')) * 10
				+
				(SELECT COUNT(*)
				FROM polls
				WHERE bill_id=bills.id AND (DATEDIFF(now(), date_created) <= ' . $days . ')) * 2
				+
				(SELECT COUNT(*)
				FROM comments_subscriptions
				WHERE bill_id=bills.id AND (DATEDIFF(now(), date_created) <= ' . $days . ')) * 2
			)
		WHERE session_id=' . $session_id;
$query($sql);

###
# UPDATE BILLS' INTRESTINGNESS RANKING
# Each bill is ranked by how interesting it is. This is a metric for how interested that people are
# in a given bill. This is based on the entirety of the data that we have on this bill. (Compare
# this with "hotness," which is based on recent interest.) The formula itself is based on five
# factors: comments, views, Photosynthesis adds, poll votes, and subscriptions to comments.
###
$sql = 'UPDATE bills
		SET interestingness=
			(
				(SELECT COUNT(*)
				FROM comments
				WHERE bill_id=bills.id) * 5
				+
				(SELECT COUNT(DISTINCT ip)
				FROM bills_views
				WHERE bill_id=bills.id) / 3
				+
				(SELECT COUNT(*)
				FROM dashboard_bills
				WHERE bill_id=bills.id) * 10
				+
				(SELECT COUNT(*)
				FROM polls
				WHERE bill_id=bills.id) * 2
				+
				(SELECT COUNT(*)
				FROM comments_subscriptions
				WHERE bill_id=bills.id) * 2
			)
		WHERE session_id=' . $session_id;
$query($sql);


###
# UPDATE BILLS' COPATRON COUNT
# The bill stores the number of copatrons that it has. Update that number by refreshing it from the
# bills_copatrons table. We don't bother with limiting it to the current session because, hey, it
# won't hurt.
###
$sql = 'UPDATE bills
		SET copatrons =
			(SELECT COUNT(*)
			FROM bills_copatrons
			WHERE bill_id=bills.id)
		WHERE session_id = ' . $session_id;
$query($sql);


###
# DELETE ORPHANED FULL TEXT
# Any full text entries that have no associated bills can be erased.
###
$sql = 'DELETE FROM bills_full_text
		WHERE text IS NULL
		AND 
			(SELECT COUNT(*)
			FROM bills
			WHERE
				id=bills_full_text.bill_id) = 0';
$query($sql);

###
# UPDATE VOTES CONTENTION RANKING
# Calculate how contested that each vote was, on a 0-1 scale. Zero means that it passed unanimously,
# while one means that it was a 50/50 vote.
###

# Generate a listing of every vote that doesn't have a contested rating.
$sql = 'SELECT id, tally
		FROM votes
		WHERE contested IS NULL';
$result = $query($sql);
if ($result !== false && mysqli_num_rows($result) > 0) {
    while ($vote = mysqli_fetch_array($result)) {
        # If the vote is more than XX-YY (such as XX-YY-ZZ, or XX-YY-ZZ-AA), then we hack off
        # everything after XX-YY.
        if (substr_count($vote['tally'], '-') > 1) {
            # Disassemble the tally and hack off any final bit.
            $tmp = explode('-', $vote['tally']);
            $tmp = array_slice($tmp, 0, 2);

            # We get the vote total from adding up the results of the Ys and the Ns. Though the
            # total number of votes cast is stored in the database, that includes the absentions
            # and absent votes, which isn't helpful here.
            $vote['total'] = array_sum($tmp);

            # Put the tally back together.
            $vote['tally'] = implode('-', $tmp);
        }

        # If either the first (Ys) or second (Ns) element of that temporary array is a zero, then
        # we know that this vote wasn't contested at all. Also, we know the same thing if the vote
        # total is zero. One would think that the first two conditions would obviate the third,
        # but, oddly, that's not so in practice.
        if (($tmp[0] == 0) || ($tmp[1] == 0) || ($vote['total'] == 0)) {
            $vote['contested'] = 0;
        }

        # If the vote was contested, then proceed to do the math to determine how contested that it
        # was.
        else {
            $tally_parts = array_map('intval', explode('-', $vote['tally']));
            $vote['contested'] = $tally_parts[0];
            for ($j = 1; $j < count($tally_parts); $j++) {
                $vote['contested'] -= $tally_parts[$j];
            }
            $vote['contested'] = round((abs($vote['contested']) / $vote['total']), 2);

            # Reverse this number on the 0-1 scale.
            if ($vote['contested'] < .5) {
                $vote['contested'] = 1 - $vote['contested'];
            } elseif ($vote['contested'] > .5) {
                $vote['contested'] = 0 + (1 - $vote['contested']);
            }
        }

        # Update the votes table with this contested rating.
        $sql = 'UPDATE votes
				SET contested=' . $vote['contested'];
        # If the vote wasn't contested, then it also wasn't partisan.
        if ($vote['contested'] == 0) {
            $sql .= ', partisanship=0';
        }
        $sql .= ' WHERE id=' . $vote['id'];
        $query($sql);
    }
}

###
# DISABLE STATUS NARRATIVES WHEN STATUS HAS CHANGED
# When a bill has advanced further, then any status narrative that has been built for it should
# be assumed to be out of date. Here we disable any status narrative for a bill when the bill's
# status has substantially change since the narrative was created.
#
# This compares when we recorded each status (date_created), not the date of the action (date).
# Action dates have no time of day, so a status recorded later on the day a narrative was written
# looked older than the narrative and never retired it, and neither did a status that LIS posted
# after a delay. Recording times also can't be in the future, so they can't retire a narrative
# on every run.
###
$sql = 'UPDATE bills_status_narratives
        SET current="n"
        WHERE
            session_id = ' . SESSION_ID . ' AND
            current="y" AND
            date_created < (
                SELECT MAX(date_created)
                FROM bills_status
                WHERE
                    bill_id = bills_status_narratives.bill_id AND
                    translation IS NOT NULL
            )';
$query($sql);

if ($failed_statements > 0) {
    return \RsMachine\TaskResult::failed($failed_statements . ' statements failed; see the log');
}
