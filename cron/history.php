<?php

# Update the history only if the query has EXPLICITLY called for it by appending ?history=y
if (isset($_GLOBAL['history']))
{

	/*
	 * Connect to Memcached, since we'll be interacting with it during this session.
	 */
	$mc = new Memcached();
	$mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);

	/*
	 * Don't bother if the file hasn't changed.
	 */
	$history_hash = md5(file_get_contents(__DIR__ . '/history.csv'));
	if ( $mc->get('history-csv-hash') == $history_hash )
	{

		/*
		 * But if it's been more than six hours, go ahead and parse it anyway, just in case
		 * something has gone wrong with the hash storage.
		 */
		if ( time() - filemtime(__DIR__ . '/history.csv') < (60 * 60 * 6) )
		{
			$log->put('Bill histories unchanged', 2);
			return;
		}
		
	}

	/*
	 * Save the new hash.
	 */
	$mc->set('history-csv-hash', $history_hash);

	# Open history.csv.
	$fp = fopen(__DIR__ . '/history.csv','r');
	if ($fp === FALSE)
	{
		$log->put('history.csv could not be read from the filesystem.', 8);
		echo 'history.csv could not be read from the filesystem.';
		return FALSE;
	}

	# Retrieve our saved serialized array of hash data, so that we can only update or insert
	# bills that have changed, or that are new.
	$hash_path = __DIR__ . '/hashes/history-' . SESSION_ID . '.md5';
	if (file_exists($hash_path))
	{
		$hashes = file_get_contents($hash_path);
		if ($hashes !== FALSE)
		{
			$hashes = explode("\n", $hashes);
			unlink($hash_path);
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

	# Prepare our query for updating the chamber that bills are in.
	$chamber_stmt = $dbh->prepare('UPDATE bills
									SET current_chamber = :current_chamber
									WHERE number = :bill_number AND session_id = :session_id');

	# Prepare 2 bill-status update queries. The first one is if we don't have an LIS vote ID.
	$status_stmt = $dbh->prepare('REPLACE INTO bills_status
									SET bill_id =
										(SELECT id
										FROM bills
										WHERE number = :bill_number
										AND session_id = :session_id),
									status = :bill_status,
									session_id = :session_id,
									date = :bill_date,
									date_created=now()');

	# Our second bill-status update query is for when we *do* have an LIS vote ID.
	$status_vote_stmt = $dbh->prepare('REPLACE INTO bills_status
										SET bill_id =
											(SELECT id
											FROM bills
											WHERE number = :bill_number
											AND session_id = :session_id),
										status = :bill_status, lis_vote_id = :lis_vote_id,
										session_id = :session_id,
										date = :bill_date,
										date_created=now()');


	# Set a flag that will allow us to ignore the header row.
	$first = 'yes';

	# Step through each row in the CSV file, one by one.
	$i=0;
	while (($bill = fgetcsv($fp, 1000, ',')) !== FALSE)
	{

		# If this is the header row, skip it.
		if (isset($first))
		{
			unset($first);
			continue;
		}

		###
		# Before we proceed any farther, see if we already have this record on file.
		###
		$hash = md5(serialize($bill));
		if (in_array($hash, $hashes) === TRUE)
		{
			continue;
		}
		else
		{
			$hashes[] = $hash;
		}

		# Provide friendlier array element names.
		$bill['number'] = strtolower($bill[0]);
		$bill['date'] = $bill[1];
		$bill['status'] = $bill[2];
		$bill['lis_vote_id'] = $bill[3];

		# Determine if this is in the House or the Senate.
		if ($bill['number']{0} == 'h') $bill['chamber'] = 'house';
		elseif ($bill['number']{0} == 's') $bill['chamber'] = 'senate';

		# Only proceed if we've gotten a meaningful bill chamber.
		if (isset($bill['chamber']))
		{

			# Clean up the data.
			if (substr($bill['status'], 0, 2) == 'H ')
			{
				$bill['status'] = substr($bill['status'], 2);
				$bill['current_chamber'] = 'house';
			}
			elseif (substr($bill['status'], 0, 2) == 'S ')
			{
				$bill['status'] = substr($bill['status'], 2);
				$bill['current_chamber'] = 'senate';
			}
			else
			{
				$bill['current_chamber'] = $bill['chamber'];
			}
			$bill['date'] = strtotime($bill['date']);
			$bill['date'] = date('Y-m-d', $bill['date']);

			# Only insert the data if we have a reasonable date.
			if ($bill['date'] != '1969-12-31')
			{

				if (empty($bill['lis_vote_id']))
				{
					$status_stmt->bindParam(':bill_number', $bill['number']);
					$status_stmt->bindParam(':session_id', $session_id);
					$status_stmt->bindParam(':bill_status', $bill['status']);
					$status_stmt->bindParam(':bill_date', $bill['date']);
					$result = $status_stmt->execute();
				}
				else
				{
					$status_vote_stmt->bindParam(':bill_number', $bill['number']);
					$status_vote_stmt->bindParam(':session_id', $session_id);
					$status_vote_stmt->bindParam(':bill_status', $bill['status']);
					$status_vote_stmt->bindParam(':bill_date', $bill['date']);
					$status_vote_stmt->bindParam(':lis_vote_id', $bill['lis_vote_id']);
					$result = $status_vote_stmt->execute();
				}

				$chamber_stmt->bindParam(':current_chamber', $bill['current_chamber']);
				$chamber_stmt->bindParam(':bill_number', $bill['number']);
				$chamber_stmt->bindParam(':session_id', $session_id);
				$result = $chamber_stmt->execute();

				if ($result === FALSE)
				{
					$log->put('SQL statement failed when adding history data for ' . $bill['number'], 7);
				}

				# Since we've just modified a bill record, we want to delete its cache entry.
				# This will allow it to be rebuilt with fresh information.
				$bill['id'] = $mc->get('bill-' . $bill['number']);
				if ($bill['id'] != FALSE)
				{
					$mc->delete('bill-' . $bill['id']);
				}

			}

			$i++;

			# Every 100 records, write this data to a file and reset our hashes array.
			if ( ($i % 100) === 0)
			{
				file_put_contents($hash_path, implode("\n", $hashes) . "\n", FILE_APPEND);
				$hashes = array();
			}

		} // end looping through lines in this CSV file

	}

	# Store whatever hashes remain. (Which may well be all of them, if fewer than 100 new or
	# changed records were found.)
	file_put_contents($hash_path, implode("\n", $hashes) . "\n", FILE_APPEND);

	# Close the CSV file.
	fclose($fp);

	$log->put('Updated bill histories', 2);

}
