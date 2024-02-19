<?php

###
# MISC. DATA EXPORT FUNCTOINS
# By Waldo Jaquith <waldo@jaquith.org>
# 02/10/2011
#
# PURPOSE
# Dumps a bunch of data to flat files for folks to download in bulk.
#
# NOTES
# This won't work if called on its own -- it will only function when invoked from within
# update_db.php.
#
###

$downloads_dir = __DIR__ . '/../downloads/';

/*
 * Make sure that our downloads directory exists and is writable.
 */
if (file_exists($downloads_dir) == false) {
    mkdir($downloads_dir);
}
if (is_writeable($downloads_dir) == false) {
    $log->put('Could not write to downloads directory', 8);
    echo 'No data found on DLAS’s FTP server.';
    return false;
}

# Save a listing of the proposed changes to laws as JSON.
$sql = 'SELECT UPPER(bills.number) AS bill_number, bills.catch_line AS bill_catch_line,
		bills_section_numbers.section_number AS law
		FROM bills_section_numbers
		LEFT JOIN bills
			ON bills_section_numbers.bill_id = bills.id
		WHERE bills.session_id = ' . SESSION_ID;
$result = mysqli_query($GLOBALS['db'], $sql);

if (mysqli_num_rows($result) > 0) {
    $changes = array();
    while ($change = mysqli_fetch_array($result, MYSQL_ASSOC)) {
        $change['url'] = 'https://www.richmondsunlight.com/bill/' . SESSION_YEAR . '/'
            . strtolower($change['bill_number']) . '/';
        $changes[] = $change;
    }

    $changes = json_encode($changes);
    if (is_writeable($downloads_dir . 'law-changes.json')) {
        file_put_contents($downloads_dir . 'law-changes.json', $changes);
    }
}


# A list of legislators.
$sql = 'SELECT representatives.chamber, representatives.name, representatives.date_started,
		representatives.party, districts.number, districts.description, representatives.sex,
		representatives.email, representatives.url, representatives.place, representatives.latitude,
		representatives.longitude, representatives.lis_shortname, representatives.lis_shortname,
		representatives.lis_id, representatives.shortname, representatives.sbe_id
		FROM representatives
		LEFT JOIN districts
			ON representatives.district_id=districts.id
		WHERE representatives.date_ended IS NULL OR representatives.date_ended > now()
		ORDER BY name ASC';
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) > 0) {
    $csv_header = array('Chamber', 'Name', 'Date Started', 'Party', 'District #',
        'District Description', 'Sex', 'E-Mail', 'Website', 'Place Name', 'Longitude', 'Latitude',
        'LIS ID 1', 'LIS ID 2', 'RS ID', 'SBE ID');
    # Open a handle to write a file.
    $fp = fopen($downloads_dir . 'legislators.csv', 'w');
    if ($fp === false) {
        $log->put('Could not write to ' . $downloads_dir . 'legislators.csv.', 8);
        return false;
    }
    fputcsv($fp, $csv_header);

    while ($bill = mysqli_fetch_array($result, MYSQL_ASSOC)) {
        $bill = array_map('stripslashes', $bill);
        fputcsv($fp, $bill);
    }

    # Close the file.
    fclose($fp);
}


# A list of bills.
$sql = 'SELECT sessions.year, bills.chamber, bills.number, bills.catch_line,
		representatives.name AS patron, summary, status, outcome, date_introduced
		FROM bills
		LEFT JOIN representatives
			ON bills.chief_patron_id = representatives.id
		LEFT JOIN sessions
			ON bills.session_id=sessions.id
		ORDER BY sessions.year ASC, bills.chamber DESC,
		SUBSTRING(bills.number FROM 1 FOR 2) ASC,
		CAST(LPAD(SUBSTRING(bills.number FROM 3), 4, "0") AS UNSIGNED) ASC';
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) > 0) {
    $csv_header = array('Year', 'Chamber','Bill #','Catch Line','Patron','Summary','Status','Outcome',
        'Date Introduced');
    # Open a handle to write a file.
    $fp = fopen($downloads_dir . 'bills.csv', 'w');
    fputcsv($fp, $csv_header);

    while ($bill = mysqli_fetch_array($result, MYSQL_ASSOC)) {
        $bill = array_map('stripslashes', $bill);
        fputcsv($fp, $bill);
    }

    # Close the file.
    fclose($fp);
}


# A list of bills by section number.
$sql = 'SELECT sessions.year, UPPER(bills.number) AS bill, bills_section_numbers.section_number
		FROM bills
		LEFT JOIN bills_section_numbers
			ON bills.id = bills_section_numbers.bill_id
		LEFT JOIN vacode
			ON bills_section_numbers.section_number = vacode.section_number
		LEFT JOIN sessions
			ON bills.session_id = sessions.id
		WHERE vacode.section_number IS NOT NULL
		ORDER BY year ASC,
		SUBSTRING(bills.number FROM 1 FOR 2) ASC,
		CAST(LPAD(SUBSTRING(bills.number FROM 3), 4, "0") AS unsigned) ASC';
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) > 0) {
    $csv_header = array('Year','Bill #','Section #');
    # Open a handle to write a file.
    $fp = fopen($downloads_dir . 'sections.csv', 'w');
    fputcsv($fp, $csv_header);

    while ($bill = mysqli_fetch_array($result, MYSQL_ASSOC)) {
        $bill = array_map('stripslashes', $bill);
        fputcsv($fp, $bill);
    }

    # Close the file.
    fclose($fp);
}


/*
 * A list of committees and their members.
 */
$sql = 'SELECT CONCAT(UPPER(SUBSTRING(committees.chamber, 1, 1)), SUBSTRING(committees.chamber FROM 2), " ", committees.name) AS committee,
		representatives.name_formatted AS name, representatives.shortname AS id,
		committee_members.position
		FROM committees
		LEFT JOIN committee_members
			ON committees.id = committee_members.committee_id
		LEFT JOIN representatives
			ON committee_members.representative_id = representatives.id
		WHERE committees.parent_id IS NULL AND committee_members.date_ended IS NULL
		ORDER BY committees.chamber ASC, committees.name ASC, position DESC';
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) > 0) {
    $committees = array();
    while ($membership = mysqli_fetch_array($result)) {
        if (empty($membership['position'])) {
            $membership['position'] = 'member';
        }
        $committees[$membership{'committee'}][] = array($membership['name'], $membership['id'], $membership['position']);
    }
    $committees = json_encode($committees);
    file_put_contents($downloads_dir . 'committees.json', $committees);
}


# The full text of all bills.
$sql = 'SELECT sessions.year, bills_full_text.number, bills_full_text.text
		FROM bills_full_text
		LEFT JOIN bills
			ON bills_full_text.bill_id = bills.id
		LEFT JOIN sessions
			ON bills.session_id = sessions.id
		WHERE bills_full_text.text IS NOT NULL AND bills.number IS NOT NULL
		AND bills.session_id = ' . SESSION_ID . '
		ORDER BY sessions.year ASC, bills_full_text.number ASC';
$result = mysqli_query($GLOBALS['db'], $sql);

if (mysqli_num_rows($result) > 0) {
    if (file_exists($downloads_dir . 'bills/') === false) {
        $success = mkdir($downloads_dir . 'bills/');
        if ($success === false) {
            $log->put('Could not create ' . $downloads_dir . 'bills/ directory', 7);
        }
    }

    # Rather than check each time if the year's directory exists, just keep track here.
    $exists = array();

    while ($bill = mysqli_fetch_array($GLOBALS['db'], $result, MYSQL_ASSOC)) {
        $bill = array_map('stripslashes', $bill);

        # Neaten up the bill text.
        $bill['text'] = preg_replace("/\n\s+/", "\n", $bill['text']);
        $bill['text'] = preg_replace("/\s\n/", " ", $bill['text']);
        $bill['text'] = str_replace(' </p>', '</p>', $bill['text']);
        $bill['text'] = str_replace('<p> ', '<p>', $bill['text']);
        $bill['text'] = str_replace('  ', ' ', $bill['text']);
        $bill['text'] = str_replace("</p>\n<p>", "</p>\n\n<p>", $bill['text']);

        # Convert the bill number to lowercase.
        $bill['number'] = strtolower($bill['number']);

        # If we're encountering this year for the first time in this process, then check if the
        # directory already exists. If it doesn't exist, create it.
        if (!in_array($bill['year'], $exists)) {
            if (file_exists($downloads_dir . 'bills/' . $bill['year']) === false) {
                $success = mkdir($downloads_dir . 'bills/' . $bill['year']);
                if ($success === false) {
                    $log->put('Could not create directory ' . $downloads_dir . 'bills/' . $bill['year'], 8);
                    return false;
                }
            }

            # Make a note that this year's directory already exists.
            $exists[] = $bill['year'];
        }

        # If the file doesn't already exist, save it.
        if (file_exists($downloads_dir . 'bills/' . $bill['year'] . '/' . $bill['number'] . '.html') === false) {
            file_put_contents($downloads_dir . 'bills/' . $bill['year'] . '/' . $bill['number'] . '.html', $bill['text']);
        }
    }
}



# Video clips.
$filename = $downloads_dir . 'video-index.json';
if (is_writeable($filename)) {
    # There's too much data to hold in a single array, so we output our JSON piecemeal. Start things
    # off by writing an opening bracket.
    file_put_contents($filename, '[');

    # Get a listing of every clip.
    $sql = 'SELECT files.path, files.date, files.chamber, video_clips.time_start, video_clips.time_end,
				representatives.shortname AS legislator, UPPER(bills.number) AS bill
			FROM video_clips
			LEFT JOIN files
				ON video_clips.file_id = files.id
			LEFT JOIN representatives
				ON video_clips.legislator_id = representatives.id
			LEFT JOIN bills
				ON video_clips.bill_id = bills.id
			ORDER BY files.date ASC, files.chamber ASC, video_clips.time_start ASC';
    $result = mysqli_query($GLOBALS['db'], $sql);
    if (mysqli_num_rows($result) > 0) {
        $clips = array();
        while ($clip = mysqli_fetch_array($result, MYSQL_ASSOC)) {
            if (substr($clip['path'], 0, 1) == '/') {
                $clip['path'] = 'https://www.richmondsunlight.com' . $clip['path'];
            }
            # Write this clip, as JSON, to our file.
            file_put_contents($downloads_dir . 'video-index.json', json_encode($clip) . ',', FILE_APPEND);
        }
    }

    # Wrap up by hacking off the last character (an extraneous comma) and adding a closing bracket.
    $fp = fopen($filename, 'r+');
    ftruncate($fp, filesize($filename) - 1);
    fseek($fp, 0, SEEK_END);
    fwrite($fp, ']');
    fclose($fp);
}
