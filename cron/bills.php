<?php

# Retrieve the CSV data and save it to a local file. We make sure that it's non-empty because
# otherwise, if the connection fails, we end up with a zero-length file.
$url = 'ftp://' . LIS_FTP_USERNAME . ':' . LIS_FTP_PASSWORD . '@legis.state.va.us/fromdlas/csv'
	. $dlas_session_id . '/BILLS.CSV';
$bills = Import::update_bills_csv($url);
if (!$bills)
{
	exit;
}

/*
 * Remove any white space.
 */
$bills = trim($bills);

/*
 * Save the bills locally.
 */
if (file_put_contents(__DIR__ . '/bills.csv', $bills) === FALSE)
{
	$log->put('bills.csv could not be saved to the filesystem.', 8);
	echo 'bills.csv could not be saved to the filesystem.';
	return FALSE;
}

/*
 * Also, retrieve our saved serialized array of hash data, so that we can only update or insert
 * bills that have changed, or that are new.
 */
$hash_path = __DIR__ . '/hashes/bills-' . SESSION_ID . '.md5';
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
 * Connect to Memcached, as we may well be interacting with it during this session.
 */
$mc = new Memcached();
$mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);

/*
 * Step through each row in the CSV, one by one.
 */
$bills = explode("\n", $bills);
unset($bills[0]);
foreach ($bills as $bill)
{

	$bill = str_getcsv($bill, ',', '"');

	###
	# Before we proceed any farther, see if this record is either new or different than last
	# time that we examined it.
	###
	$hash = md5(serialize($bill));
	$number = strtolower(trim($bill[0]));

	if ( isset($hashes[$number]) && ($hash == $hashes[$number]) )
	{
		continue;
	}
	else
	{

		$hashes[$number] = $hash;
		if (!isset($hashes[$number]))
		{
			$log->put('Adding ' . strtoupper($number) . '.', 4);
		}
		else
		{
			$log->put('Updating ' . strtoupper($number) . '.', 1);
		}

	}

	/*
	 * Clean up the bill CSV
	 */
	$bill = Import::prepare_bill($bill);

	# Prepare the data for the database.
	array_walk_recursive($bill, function($field) { $field = mysqli_real_escape_string($GLOBALS['db'], $field); } );
	
	# Check to see if the bill is already in the database.
	$sql = 'SELECT id
			FROM bills
			WHERE number="' . $bill['number'] . '" AND session_id=' . $session_id;
	$result = mysqli_query($GLOBALS['db'], $sql);

	if (mysqli_num_rows($result) > 0)
	{

		$sql = 'UPDATE bills SET ';
		$existing_bill = mysqli_fetch_assoc($result);
		$sql_suffix = ' WHERE id=' . $existing_bill['id'];

		# Now that we know we're updating a bill, rather than adding a new one, delete the bill from
		# Memcached.
		$mc->delete('bill-' . $existing_bill['id']);

	}
	else
	{
		$sql = 'INSERT INTO bills SET date_created=now(), ';
	}

	# Now create the code to insert the bill or update the bill, depending
	# on what the last query established for the preamble.
	$sql .= 'number="' . $bill['number'] . '", session_id="' . $session_id.'",
			chamber="' . $bill['chamber'] . '", catch_line="' . $bill['catch_line'].'",
			chief_patron_id=
				(SELECT id
				FROM representatives
				WHERE
					lis_id = "' . $bill['chief_patron_id'] . '"
				AND (
						date_ended IS NULL
						OR
						YEAR(date_ended)+1 = YEAR(now())
						OR
						YEAR(date_ended) = YEAR(now())
					)
				AND chamber = "' . $bill['chamber'] . '"),
			last_committee_id=
				(SELECT id
				FROM committees
				WHERE lis_id = "' . $bill['last_committee'] . '" AND parent_id IS NULL
				AND chamber = "' . $bill['last_committee_chamber'] . '"),
			status="'.$bill['status'].'"';
	if (isset($sql_suffix))
	{
		$sql = $sql . $sql_suffix;
	}

	$result = mysqli_query($GLOBALS['db'], $sql);

	if ($result === FALSE)
	{
		$log->put('Adding ' . $bill['number'] . ' failed. Almost certainly, this means that '
			. 'the legislator (' . $bill['chief_patron_id'] . ', '
			. strtolower($bill['chief_patron']) . ') who filed this bill isn’t in the database.', 7);
		unset($hashes[$number]);
	}

	else
	{

		# Get the last bill insert ID.
		if (!isset($existing_bill['id']))
		{
			$bill['id'] = mysqli_insert_id($GLOBALS['db']);
		}
		else
		{
			$bill['id'] = $existing_bill['id'];
		}

		# Create a bill full text record for every version of the bill text that's filed.
		for ($i=0; $i<count($bill['text']); $i++)
		{
			if (!empty($bill['text'][$i]['number']) && !empty($bill['text'][$i]['date']))
			{
				$sql = 'INSERT INTO bills_full_text
						SET bill_id = ' . $bill['id'] . ', number="' . $bill['text'][$i]['number'] . '",
						date_introduced="' . $bill['text'][$i]['date'] . '", date_created=now()
						ON DUPLICATE KEY UPDATE date_introduced=date_introduced';
				mysqli_query($GLOBALS['db'], $sql);
			}
		}
	}

	# Unset those variables to avoid reuse.
	unset($sql_suffix);
	unset($bill['id']);
	unset($existing_bill);

} // end looping through lines in this CSV file

# Store our per-bill hashes array to a file, so that we can open it up next time and see which
# bills have changed.
file_put_contents($hash_path, serialize($hashes));
