<?php

class Import
{

	/**
	 *	Fetch the latest bill CSV
	*/
	function update_bills_csv($url)
	{

		if (empty($url))
		{
			return FALSE;
		}

		$bills = get_content($url);

		if (!$bills || empty($bills))
		{
			$log->put('BILLS.CSV doesn’t exist on legis.state.va.us.', 8);
			echo 'No data found on DLAS’s FTP server.';
			return FALSE;
		}

		# If the MD5 value of the new file is the same as the saved file, then there's nothing to update.
		if (md5($bills) == md5_file('bills.csv'))
		{
			$log->put('Not updating bills, because bills.csv has not been modified since it was last downloaded.', 2);
			return FALSE;
		}

		return $bills;

	}

	/***
	 * Turn the CSV array into well-formatted, well-named fields.
	 */
	function prepare_bill($bill)
	{

		if (empty($bill))
		{
			return FALSE;
		}

		# Provide friendlier array element names.
		$bill['number'] = strtolower(trim($bill[0]));
		$bill['catch_line'] = trim($bill[1]);
		$bill['chief_patron_id'] = substr(trim($bill[2]), 1);
		$bill['chief_patron'] = trim($bill[3]);
		$bill['last_house_committee'] = trim($bill[4]);
		$bill['last_house_date'] = strtotime(trim($bill[6]));
		$bill['last_senate_committee'] = trim($bill[7]);
		$bill['last_senate_date'] = strtotime(trim($bill[9]));
		$bill['passed_house'] = trim($bill[15]);
		$bill['passed_senate'] = trim($bill[16]);
		$bill['passed'] = trim($bill[17]);
		$bill['failed'] = trim($bill[18]);
		$bill['continued'] = trim($bill[19]);
		$bill['approved'] = trim($bill[20]);
		$bill['vetoed'] = trim($bill[21]);

		# The following are versions of the bill's full text. Only the first pair need be
		# present. But the remainder are there to deal with the possibility that the bill is
		# amended X times.
		$bill['text'][0]['number'] = trim($bill[22]);
		$bill['text'][0]['date'] = date('Y-m-d', strtotime(trim($bill[23])));
		if (!empty($bill[24])) $bill['text'][1]['number'] = trim($bill[24]);
		if (!empty($bill[25])) $bill['text'][1]['date'] = date('Y-m-d', strtotime(trim($bill[25])));
		if (!empty($bill[26])) $bill['text'][2]['number'] = trim($bill[26]);
		if (!empty($bill[27])) $bill['text'][2]['date'] = date('Y-m-d', strtotime(trim($bill[27])));
		if (!empty($bill[28])) $bill['text'][3]['number'] = trim($bill[28]);
		if (!empty($bill[29])) $bill['text'][3]['date'] = date('Y-m-d', strtotime(trim($bill[29])));
		if (!empty($bill[30])) $bill['text'][4]['number'] = trim($bill[30]);
		if (!empty($bill[31])) $bill['text'][4]['date'] = date('Y-m-d', strtotime(trim($bill[31])));
		if (!empty($bill[32])) $bill['text'][5]['number'] = trim($bill[32]);
		if (!empty($bill[33])) $bill['text'][5]['date'] = date('Y-m-d', strtotime(trim($bill[33])));

		# Determine if this was introduced in the House or the Senate.
		if ($bill['number']{0} == 'h')
		{
			$bill['chamber'] = 'house';
		}
		elseif ($bill['number']{0} == 's')
		{
			$bill['chamber'] = 'senate';
		}

		# Set the last committee to be the committee in the chamber in which there was most recently
		# activity.
		if (empty($bill['last_house_date']))
		{
			$bill['last_house_date'] = 0;
		}
		if (empty($bill['last_senate_date']))
		{
			$bill['last_senate_date'] = 0;
		}
		if ($bill['last_house_date'] > $bill['last_senate_date'])
		{
			$bill['last_committee'] = substr($bill['last_house_committee'], 1);
			$bill['last_committee_chamber'] = 'house';
		}
		else
		{
			$bill['last_committee'] = substr($bill['last_senate_committee'], 1);
			$bill['last_committee_chamber'] = 'senate';
		}

		# Determine the latest status.
		if ($bill['approved'] == 'Y')
		{
			$bill['status'] = 'approved';
		}
		elseif ($bill['vetoed'] == 'Y')
		{
			$bill['status'] = 'vetoed';
		}
		# Only flag the bill as continued if it's from after Feb. '08.  This will
		# need to be updated periodically.
		elseif ($bill['continued'] == 'Y')
		{
			if (($bill['last_house_date'] > strtotime('01 February 2008'))
				&& ($bill['last_senate_date'] > strtotime('01 February 2008')))
			{
				$bill['status'] = 'continued';
			}
			else
			{
				$bill['status'] = 'failed';
			}
		}
		elseif ($bill['failed'] == 'Y') $bill['status'] = 'failed';
		elseif ($bill['passed'] == 'Y') $bill['status'] = 'passed';
		elseif ($bill['passed_senate'] == 'Y') $bill['status'] = 'passed senate';
		elseif ($bill['passed_house'] == 'Y') $bill['status'] = 'passed house';
		elseif (!empty($bill['last_senate_committee']) || !empty($bill['last_house_committee']))
		{
			$bill['status'] = 'in committee';
		}
		else
		{
			$bill['status'] = 'introduced';
		}

		# Create an instance of HTML Purifier to clean up the text.
		//$config = HTMLPurifier_Config::createDefault();
		//$purifier = new HTMLPurifier($config);

		# Purify the HTML and trim off the surrounding whitespace.
		//$bill['catch_line'] = trim($purifier->purify($bill['catch_line']));

		return $bill;

	}

	# Look up a legislator's ID.
	function lookup_legislator_id($legislators, $lis_id)
	{

		# Determine the chamber.
		if ($lis_id{0} == 'H')
		{
			$chamber = 'house';
		}
		elseif ($lis_id{0} == 'S')
		{
			$chamber = 'senate';
		}

		# Bizarrely, LIS often (but not always) identifies the House speaker
		# as "Mr. Speaker" and uses the ID of "H0000," regardless of the real
		# ID of that delegate.  Translate that ID here.
		if ($lis_id == 'H0000')
		{
			$lis_id = HOUSE_SPEAKER_LIS_ID;
		}

		# Translate the LIS ID, stripping letters and removing leading 0s.
		$lis_id = preg_replace('/[A-Z]/D', '', $lis_id);
		$lis_id = round($lis_id);

		for ($i=0; $i<count($legislators); $i++)
		{
			if (($legislators[$i]['lis_id'] == $lis_id) && ($legislators[$i]['chamber'] == $chamber))
			{
				return $legislators[$i]['id'];
			}
		}
		return FALSE;

	}

	# Look up a committee's ID.
	function lookup_committee_id($committees, $lis_id)
	{

		# Determine the chamber.
		if ($lis_id{0} == 'H')
		{
			$chamber = 'house';
		}
		elseif ($lis_id{0} == 'S')
		{
			$chamber = 'senate';
		}

		# Translate the LIS ID, stripping letters and removing leading 0s.
		$lis_id = substr($lis_id, 1, 2);
		$lis_id = round($lis_id);

		for ($i=0; $i<count($committees); $i++)
		{
			if (($committees[$i]['lis_id'] == $lis_id) && ($committees[$i]['chamber'] == $chamber))
			{
				return $committees[$i]['id'];
			}
		}
		return FALSE;

	}
	
}
