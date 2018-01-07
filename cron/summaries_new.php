<?php

###
# UPDATE BILL SUMMARIES
###

/*
 * Fetch the CSV file.
 */
$summaries = get_content('ftp://' . LIS_FTP_USERNAME . ':' . LIS_FTP_PASSWORD
	. '@legis.state.va.us/fromdlas/csv' . $dlas_session_id . '/Summaries.csv');
if (!$summaries || empty($summaries))
{
	$log->put('Summaries.csv doesn’t exist on legis.state.va.us.', 8);
	return FALSE;
}

# If the MD5 value of the new file is the same as the saved file, then there's nothing to update.
if (md5($summaries) == md5_file('summaries.csv'))
{
	$log->put('Not updating summaries, because summaries.csv has not been modified since it was last downloaded.', 2);
	return FALSE;
}

/*
 * Remove any white space.
 */
$summaries = trim($summaries);

/*
 * Save the summaries locally.
 */
if (file_put_contents(__DIR__ . '/summaries.csv', $summaries) === FALSE)
{
	$log->put('summaries.csv could not be saved to the filesystem.', 8);
	return FALSE;
}

/*
 * Open the resulting file.
 */
$fp = fopen(__DIR__ . '/summaries.csv','r');
if ($fp === FALSE)
{
	$log->put('summaries.csv could not be read from the filesystem.', 8);
	return FALSE;
}

/*
 * Also, retrieve our saved serialized array of hash data, so that we can only update or insert
 * summaries that have changed, or that are new.
 */
$hash_path = __DIR__ . '/hashes/summaries-' . SESSION_ID . '.md5';
if (file_exists($hash_path))
{
	$hashes = file_get_contents($hash_path);
	if ($hashes !== FALSE)
	{
		$hashes = unserialize($hashes);
	}
	else
	{
		$hashes = array();
	}
}
else
{
	if (!file_exists(__DIR__ . '/hashes/'))
	{
		mkdir(__DIR__ . '/hashes');
	}
	$hashes = array();
}

/*
 * Generate a list of all bills and their numbers, to use to make comparisons.
 */
$sql = 'SELECT bills.id, bills.number
		FROM bills
		WHERE session_id = ' . $session_id;
$result = mysql_query($sql);
if (mysql_num_rows($result) > 0)
{
	$bills = array();
	while ($bill = mysql_fetch_array($result))
	{
		$bills[$bill{number}] = $bill['id'];
	}
}

/*
 * Set a flag that will allow us to ignore the header row.
 */
$first = 'yes';

/*
 * Step through each row in the CSV file, one by one.
 */
while (($summary = fgetcsv($fp, 1000, ',')) !== FALSE)
{
	
	# If this is something other than a header row, parse it.
	if (isset($first))
	{
		unset($first);
		continue;
	}

	/*
	 * Rename each field to something reasonable.
	 */
	$new_headers = array(
			'number',
			'doc_id',
			'type',
			'text'
		);
	foreach ($new_headers as $old => $new)
	{
		$summary[$new] = $summary[$old];
		unset($summary[$old]);
	}

	/*
	 * Change the format of the bill number. In this file, the numeric portions are left-padded
	 * with zeros, so that e.g. HB1 is rendered as HB0001. Here we change them to e.g. HB1.
	 */
    $suffix = substr($summary['number'], 2) + 0;
    $summary['number'] = substr($summary['number'], 0, 2) . $suffix;


	/*
	 * Before we proceed any farther, see if this record is either new or different than last
	 * time that we examined it.
	 */
	$hash = md5(serialize($summary));
	$number = strtolower($bill['number']);
	
	if ( isset($hashes[$number]) && ($hash == $hashes[$number]) )
	{
		continue;
	}
	else
	{
	
		$hashes[$number] = $hash;
		if (!isset($hashes[$number]))
		{
			$log->put('Adding summary ' . strtoupper($number) . '.', 2);
		}
		else
		{
			$log->put('Updating summary ' . strtoupper($number) . '.', 1);
		}
		
	}

	/*
	 * Remove the paragraph tags, newlines, NBSPs and double spaces.
	 */
	$summary['text'] = str_replace("\r", ' ', $summary['text']);
	$summary['text'] = str_replace("\n", ' ', $summary['text']);
	$summary['text'] = str_replace('&nbsp;', ' ', $summary['text']);
	$summary['text'] = str_replace('  ', ' ', $summary['text']);
	$summary['text'] = str_replace('\u00a0', ' ', $summary['text']);
		
	# There is often an HTML mistake in this tag, so we perform this replacement after
	# running HTML Purifier, not before.
	$summary['text'] = str_replace('<br clear="all" /> ', ' ', $summary['text']);
	$summary['text'] = strip_tags($summary['text'], '<b><i><em><strong>');

	# Run the summary through HTML Purifier.
	$config = HTMLPurifier_Config::createDefault();
	$purifier = new HTMLPurifier($config);
	$summary['text'] = $purifier->purify($summary['text']);
	
	# Clean up the bolding, so that we don't bold a blank space.
	$summary['text'] = str_replace(' </b>', '</b> ', $summary['text']);
	
	# Trim off any whitespace.
	$summary['text'] = trim($summary['text']);
		
	# Hack off a hanging non-breaking space, if there is one.
	if (substr($summary['text'], -7) == ' &nbsp;')
	{
		$summary['text'] = substr($summary['text'], 0, -8);
	}
		
	/*
	 * If we have any summary text, store it in the database.
	 */
	if (!empty($summary['text']))
	{

		/*
		 * Look up the bill ID for this bill number.
		 */
		$bill_id = $bills[strtolower($summary{number})];
		if (is_empty($bill_id))
		{
			$log->put('Summary found for '. $summary['number']
				. ', but we have no record of that bill.', 2);
			continue;
		}

		/*
		 * Commit this to the database.
		 */
		$sql = 'UPDATE bills
				SET summary="' . mysql_real_escape_string($summary['text']) . '"
				WHERE id="' . $bill_id . '"
				AND session_id = ' . $session_id;
		$result = mysql_query($sql);
		if (!$result)
		{
			$log->put('Insertion of '. strtoupper($bill['number']) . ' summary failed.', 6);
		}
		else
		{
			$log->put('Insertion of '. strtoupper($bill['number']) . ' summary succeeded.', 1);
		}

	}
	else
	{
		$log->put('Summary of ' . strtoupper($bill['number']) . ' is blank.', 2);
	}

} // end looping through lines in this CSV file

# Close the CSV file.
fclose($fp);

# Store our per-bill hashes array to a file, so that we can open it up next time and see which
# bills have changed.
file_put_contents($hash_path, serialize($hashes));
