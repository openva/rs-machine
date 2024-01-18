<?php

###
# Retrieve and Store Minutes
#
# PURPOSE
# Retrieves the minutes from every meeting of the House and Senate and stores them.
#
###

# PAGE CONTENT
$sql = 'SELECT date, chamber
		FROM minutes';
$result = mysql_query($sql);
if (mysql_num_rows($result) > 0)
{
	while ($tmp = mysql_fetch_array($result))
	{
		$past_minutes[] = $tmp;
	}
}

$chambers['house'] = 'https://virginiageneralassembly.gov/house/minutes/list.php?ses=' . SESSION_LIS_ID;
$chambers['senate'] = 'http://leg1.state.va.us/cgi-bin/legp504.exe?ses=' . SESSION_LIS_ID . '&typ=lnk&val=07';

foreach ($chambers as $chamber => $listing_url)
{

	# Begin by connecting to the appropriate session page.
	$raw_html = get_content($listing_url);
	$raw_html = explode("\n", $raw_html);

	# Iterate through every line in the HTML.
	foreach ($raw_html as &$line)
	{

		# Check if this line contains a link to the minutes for a given date.
		if ($chamber == 'house')
		{
			preg_match('|<a href="minutes.php\?mid=([0-9]+)">(.+)</a>|', $line, $regs);
		}
		elseif ($chamber == 'senate')
		{
			preg_match('#<a href="/cgi-bin/legp504.exe\?([0-9]{3})\+min\+([A-Za-z0-9]+)">([A-Za-z]+) ([0-9]+), ([0-9]{4})</a>#D', $line, $regs);
		}

		# We've found a match.
		if (count($regs) > 0)
		{

			# Pull out the source URL and the date from the matched string.
			if ($chamber == 'house')
			{
				$source_url = 'https://hod-minutes.herokuapp.com/vga_day/' . $regs[1];
				$date = date('Y-m-d', strtotime($regs[2]));
			}
			elseif ($chamber == 'senate')
			{
				$source_url = 'http://leg1.state.va.us/cgi-bin/legp504.exe?'.$regs[1].'+min+'.$regs[2];
				$date = date('Y-m-d', strtotime($regs[3].' '.$regs[4].' '.$regs[5]));
			}

			# Determines if this is a duplicate. If a match is found, the "repeat" flag is set.
			for ($i=0; $i<count($past_minutes); $i++)
			{
				if (($past_minutes[$i]['chamber'] == $chamber) && ($past_minutes[$i]['date'] == $date))
				{
					$repeat = TRUE;
					break;
				}
			}

			# If the repeat flag is set then we've seen these minutes before, in which case continue
			# to the next line in the minutes listing.
			if ($repeat === TRUE)
			{
				unset($repeat);
				unset($date);
				unset($source_url);
				unset($regs);
				continue;
			}

			# Retrieve and clean up the minutes.
			$minutes = get_content($source_url);

			# If the query was successful.
			if ($minutes != FALSE)
			{

				# Run the minutes through HTML Purifier, just to make sure they're clean.
				$config = HTMLPurifier_Config::createDefault();
				$purifier = new HTMLPurifier($config);
				$minutes = $purifier->purify($minutes);

				# Strip out the bulk of the markup. We allow the HR tag because we sometimes use
				# it as a marker for where the page content concludes.
				$minutes = strip_tags($minutes, '<b><i><hr><br>');

				# Start the minutes with the call to order.
				$minutes = stristr($minutes, 'called to order');

				# Determine where to end the minutes. We have three versions of this strpos() to
				# accomodation variations in the data, primarily between the house and senate.
				$end = strpos($minutes, 'KEY: A');
				if ($end == FALSE)
				{
					$end = strpos($minutes, 'KEY:  A');
					if ($end == FALSE)
					{
						$end = strpos($minutes, '<hr>');
					}
					if ($end == FALSE)
					{
						$end = strpos($minutes, '<hr>');
					}
					if ($end == FALSE)
					{
						$end = strpos($minutes, 'iFrame Resizer');
					}
				}
				if ($end != FALSE)
				{
					$minutes = substr($minutes, 0, $end);
				}
				$minutes = trim($minutes);

				# Clean up some known House-minutes problems.
				if ($chamber == 'house')
				{

					$minutes = str_replace('<i class="fa fa-times"></i>', '', $minutes);
					$minutes = str_replace('<i class="fa fa-ellipsis-v"></i>', '', $minutes);
					$minutes = preg_replace("/[\r\n][[:space:]]+/", "\n\n", $minutes);
					$minutes = str_replace(' - Agreed to', " - Agreed to<br>\n", $minutes);

				}

				# Run the minutes thorugh HTML Purifier again.
				//if (!is_null($minutes)) $minutes = $purifier->purify($minutes);

				# Prepare them for MySQL.
				$minutes = mysql_real_escape_string($minutes);

				# If, after all that, we still have any text in these minutes, picking an arbitrary
				# length of 150 characters.
				if (strlen($minutes) > 150) {
					# Insert the minutes into the database.
					$sql = 'INSERT INTO minutes
							SET date = "' . $date . '", chamber="' . $chamber . '",
							text="' . $minutes . '"';
					echo $sql;
					$result = mysql_query($sql);
					if (!$result)
					{
						$log->put('Inserting the minutes for ' . $date . ' in ' . $chamber
							. ' failed. ' . $sql, 7);
					}
					else
					{

						$log->put('Inserted the minutes for ' . $date . ' in ' . $chamber . '.', 2);
					}
				}
				else {
					$log->put('The retrieved minutes for ' . $date . ' in ' . $chamber .' were '
						. ' suspiciously short, and not saved. They are as follows: ' . $minutes, 6);
				}

				# Unset our variables to prevent them from being reused on the next line.
				unset($regs);
				unset($source_url);
				unset($date);
				unset($minutes);
				unset($done);
				unset($started);
				unset($repeat);
				unset($date);
				unset($strpos);

				sleep(1);

			}

		}
	}

	# Don't accidentally reuse the HTML for the next chamber.
	unset ($raw_html);
}
