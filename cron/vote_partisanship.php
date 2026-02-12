<?php

###
# Calculate Vote Partisanship
#
# PURPOSE
# Determines how partisan that a given vote was.
#
###

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific
# page.
connect_to_db();

# PAGE METADATA
$page_title = 'Calculate Vote Partisanship';
$site_section = '';

# PAGE CONTENT
$sql = 'SELECT vote_id AS id, vote, representatives.party, COUNT(*) AS count
		FROM representatives_votes
		LEFT JOIN representatives ON representatives_votes.representative_id = representatives.id
		LEFT JOIN votes ON representatives_votes.vote_id = votes.id
		WHERE votes.partisanship IS NULL AND votes.id=1345
		GROUP BY id, party, vote';
$result = mysqli_query($GLOBALS['db'], $sql);
$row_count = mysqli_num_rows($result);

if ($row_count > 0) {
    $i = 1;
    while ($vote = mysqli_fetch_array($result)) {
        # End the prior vote-tallying session and start a new one.
        if ((isset($vote_id) && ($vote['id'] != $vote_id)) || ($i == $row_count)) {
            # This stanza is necessary to parse the data in the last line of results.
            # Otherwise they're never included. This is a repeat of a line of code
            # below.
            if ($i == $row_count) {
                # Store the vote data in an array.
                $this_vote[$vote['party']][$vote['vote']] = $vote['count'];
            }

            // LOGICAL PROBLEM
            // The problem here is that if all Rs vote Y and all Ds vote Y, the vote shows
            // up as 100% partisan. But, in fact, they're agreeing. Alternately, Ds could
            // have a 10% partisan vote and Rs a 10% partisan vote, but each 90% of each
            // party supported opposite positions. Then it shows up as only a 10% partisan
            // vote when, in fact, it's more like 90%.

            # Check to see if both sides agree, in which case we can bypass the calculation.
            if (
                (!isset($this_vote['D']['N']) && !isset($this_vote['R']['N']))
                || (!isset($this_vote['D']['Y']) && !isset($this_vote['R']['Y']))
            ) {
                $this_vote['partisanship'] = 0;
            }

            # If the vote didn't pass or fail unanimously, we can proceed to do the math.
            else {
                # Fill in any missing data.
                if (!isset($this_vote['D']['Y'])) {
                    $this_vote['D']['Y'] = 0;
                }
                if (!isset($this_vote['D']['N'])) {
                    $this_vote['D']['N'] = 0;
                }
                if (!isset($this_vote['R']['Y'])) {
                    $this_vote['R']['Y'] = 0;
                }
                if (!isset($this_vote['R']['N'])) {
                    $this_vote['R']['N'] = 0;
                }

                # Calculate Democrats' rating.
                if ($this_vote['D']['Y'] == $this_vote['D']['N']) {
                    $this_vote['D']['rating'] = 0;
                } elseif (($this_vote['D']['Y'] == 0) || ($this_vote['D']['N'] == 0)) {
                    $this_vote['D']['rating'] = 1;
                } elseif ($this_vote['D']['Y'] < $this_vote['D']['N']) {
                    $this_vote['D']['rating'] = round(($this_vote['D']['Y'] / ($this_vote['D']['Y'] + $this_vote['D']['N'])), 4);
                } elseif ($this_vote['D']['Y'] > $this_vote['D']['N']) {
                    $this_vote['D']['rating'] = round(($this_vote['D']['N'] / ($this_vote['D']['N'] + $this_vote['D']['Y'])), 4);
                }

                # Calculate Republicans' rating.
                if ($this_vote['R']['Y'] == $this_vote['R']['N']) {
                    $this_vote['R']['rating'] = 0;
                } elseif (($this_vote['R']['Y'] == 0) || ($this_vote['R']['N'] == 0)) {
                    $this_vote['R']['rating'] = 1;
                } elseif ($this_vote['R']['Y'] < $this_vote['R']['N']) {
                    $this_vote['R']['rating'] = round(($this_vote['R']['Y'] / ($this_vote['R']['Y'] + $this_vote['R']['N'])), 4);
                } elseif ($this_vote['R']['Y'] > $this_vote['R']['N']) {
                    $this_vote['R']['rating'] = round(($this_vote['R']['N'] / ($this_vote['R']['N'] + $this_vote['R']['Y'])), 4);
                }

                # Calculate the overall rating.
                $this_vote['partisanship'] = round((($this_vote['R']['rating'] + $this_vote['D']['rating']) / 2), 4);
            }

            # Create the SQL.
            $sql = 'UPDATE votes SET partisanship = ' . $this_vote['partisanship']
                . ' WHERE id = ' . (int)$vote_id;
            mysqli_query($GLOBALS['db'], $sql);
            unset($this_vote);
        }

        # Store the vote data in an array.
        $this_vote[$vote['party']][$vote['vote']] = $vote['count'];
        # Save the current vote ID so we can iterate through all of this vote's rows.
        $vote_id = $vote['id'];
        $i++;
    }
}
