<?php

/*
 * Connect to the database
 */
$database = new Database();
$db = $database->connect();
global $db;

/*
 * Instantiate the logging class
 */
$log = new Log();

/*
 * Instantiate Memcached
 */
if (MEMCACHED_SERVER != '') {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

/*
 * Use a DOM parser for the screen-scraper.
 */
use Sunra\PhpSimple\HtmlDomParser;

/*
 * Retrieve a list of all active delegates' names and IDs. Though that's not *quite* right.
 * Within a couple of weeks of the election, the House's website pretends that the departing
 * delegates are already out office. New delegates are listed, departing ones are gone. To
 * avoid two solid months of errors, instead we get a list of delegates with no end date.
 */
$sql = 'SELECT name, chamber, lis_id, date_ended
		FROM representatives
		WHERE chamber="house"
			AND date_ended IS NULL';
$stmt = $db->prepare($sql);
$stmt->execute();
$known_legislators = $stmt->fetchAll(PDO::FETCH_OBJ);

/*
 * Now get a list of senators. The senate doesn't change their list of members until the day
 * that a new session starts, so we need to use a slightly different query for them.
 */
$sql = 'SELECT name, chamber, lis_id, date_ended
		FROM representatives
		WHERE chamber="senate"
			AND (
				date_ended IS NULL
				OR
				date_ended >= NOW())';
$stmt = $db->prepare($sql);
$stmt->execute();
$known_legislators = array_merge($known_legislators, $stmt->fetchAll(PDO::FETCH_OBJ));

foreach ($known_legislators as &$known_legislator) {
    if (($known_legislator->lis_id[0] != 'S') && ($known_legislator->lis_id[0] != 'H')) {
        if ($known_legislator->chamber == 'senate') {
            $known_legislator->lis_id = 'S' . $known_legislator->lis_id;
        } elseif ($known_legislator->chamber == 'house') {
            $known_legislator->lis_id = 'H' . str_pad($known_legislator->lis_id, 4, '0', STR_PAD_LEFT);
        }
    }
}

$log->put('Loaded ' . count($known_legislators) . ' legislators from local database.', 1);
if (IN_SESSION == true && count($known_legislators) > 140) {
    $log->put('There are ' . count($known_legislators) . ' legislators in the database—too many.', 5);
}

/*
 * Get senators. Their Senate ID (e.g., "S100") is the key, their name is the value.
 */
$html = get_content('https://lis.virginia.gov/' . SESSION_LIS_ID . '/mbr/MBR.HTM');
if ($html == false) {
    $log->put('Could not load Senate listing. Abandoning efforts.', 5);
    return;
}
preg_match_all('/mbr\+S([0-9]{2,3})">(.+)<\/a>/', $html, $senators);
$tmp = array();
$i = 0;
foreach ($senators[1] as $senator) {
    $tmp['S' . $senator] = trim($senators[2][$i]);
    $i++;
}
$senators = $tmp;
unset($tmp);

$log->put('Retrieved ' . count($senators) . ' senators from senate.virginia.gov.', 1);

if (count($senators) < 35) {
    $log->put('Too few senators were found to be plausible. Abandoning efforts.', 5);
    return;
}

/*
 * Get delegates. Their House ID (e.g., "H0200") is the key, their name is the value.
 */
$html = get_content('https://virginiageneralassembly.gov/house/members/members.php?ses=' . SESSION_YEAR);
if ($html == false) {
    $log->put('Could not load House listing. Abandoning efforts.', 5);
    return;
}
preg_match_all('/id=\'member\[H([0-9]+)\]\'><td width="190px"><a class="bioOpener" href="#">(.*?)<\/a>/m', $html, $delegates);
$tmp = array();
$i = 0;
foreach ($delegates[1] as $delegate) {
    $tmp['H' . $delegate] = trim($delegates[2][$i]);
    $i++;
}
$delegates = $tmp;
unset($tmp);

$log->put('Retrieved ' . count($delegates) . ' delegates from virginiageneralassembly.gov.', 1);

if (count($delegates) < 90) {
    $log->put('Since too few delegates were found to be plausible, abandoning efforts.', 5);
    return;
}

/*
 * Invoke the Import class.
 */
$import = new Import($log);

/*
 * Update legislators' records periodically
 *
 * Select a handful of currently-serving legislators and update their records based on a re-query
 * of LIS et al. To avoid abusing the legislature's website, we select a small subset of
 * legislators to update each time, so it may be a few days before a given legislator's record
 * picks up changes.
 */
$sql = 'SELECT
			id,
			name,
			chamber,
			lis_id
		FROM representatives
		WHERE
			date_ended IS NULL
		ORDER BY RAND()
		LIMIT 10';
$stmt = $db->prepare($sql);
$stmt->execute();
$legislators = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($legislators as &$legislator) {
    $data = $import->fetch_legislator_data($legislator->chamber, $legislator->lis_id);
    $data['id'] = $legislator->id;
    $import->update_legislator($data);

    /*
     * Remove this legislator's cached record
     */
    if (MEMCACHED_SERVER != '') {
        $mc->delete('legislator-' . $legislator->id);
    }
}

/*
 * First see if we have records of any legislators who are no longer in office.
 */
foreach ($known_legislators as &$known_legislator) {
    $id = $known_legislator->lis_id;

    /*
     * Check senators.
     */
    if ($known_legislator->chamber == 'senate') {
        if (!isset($senators[$id]) && empty($known_legislator->date_ended)) {
            $csv_dir = dirname($_SERVER['SCRIPT_FILENAME']);
            try {
                $legislator_in_csv = $import->legislator_in_csv('S' . $senators[$id]);
            } catch (Exception $e) {
                $log->put('Error: Could not verify that Sen. ' . pivot($known_legislator->name)
                    . ' is no longer in office. Error thrown: ' . $e->getMessage(), 5);
                continue;
            }

            if ($legislator_in_csv == true) {
                $log->put('Error: Sen. ' . pivot($known_legislator->name) . ' is missing from '
                    . 'the website, but is still present in LIS’s CSV. They will be kept active '
                    . 'until they are removed from the CSV.', 5);
                continue;
            }

            $log->put('Error: Sen. ' . pivot($known_legislator->name)
                . ' is no longer in office, but is still listed in the database.', 5);
            if ($import->deactivate_legislator($id) == false) {
                $log->put('Error: ...but they couldn’t be marked as out of office.', 5);
            }
        }
    }

    /*
     * Check delegates.
     */
    elseif ($known_legislator->chamber == 'house') {
        if (!isset($delegates[$id]) && empty($known_legislator->date_ended)) {
            $csv_dir = dirname($_SERVER['SCRIPT_FILENAME']);
            try {
                $legislator_in_csv = $import->legislator_in_csv('H' . $delegates[$id]);
            } catch (Exception $e) {
                $log->put('Error: Could not verify that Del. ' . pivot($known_legislator->name)
                    . ' is no longer in office. Error thrown: ' . $e->getMessage(), 5);
                continue;
            }

            if ($legislator_in_csv == true) {
                $log->put('Error: Del. ' . pivot($known_legislator->name) . ' is missing from '
                    . 'the website, but is still present in LIS’s CSV. They will be kept active '
                    . 'until they are removed from the CSV.', 5);
                continue;
            }

            $log->put('Error: Del. ' . pivot($known_legislator->name)
                . ' is no longer in office, but is still listed in the database.', 5);
            if ($import->deactivate_legislator($id) == false) {
                $log->put('Error: ...but they couldn’t be marked as out of office.');
            }
        }
    }
}

/*
 * Get at least this minimum subset of fields for any senators and delegates that are not in our
 * records. (We use this list below.)
 */
$required_fields = array(
    'name_formal',
    'name',
    'name_formatted',
    'shortname',
    'chamber',
    'district_id',
    'date_started',
    'party',
    'lis_id',
    'email'
);

/*
 * Second, see there are any LIS-listed delegates or senators who are not in our records.
 */
foreach ($senators as $lis_id => $name) {
    $match = false;

    foreach ($known_legislators as &$known_legislator) {
        if ($known_legislator->lis_id == $lis_id) {
            $match = true;
            continue(2);
        }
    }

    /*
     * If we've found any new senators, call that up, and scrape their basic data from LIS
     */
    if ($match == false && $name != 'Vacant') {
        $log->put('Found a new senator: ' . $name . ' ('
            . 'https://lis.virginia.gov/cgi-bin/legp604.exe?' . SESSION_LIS_ID . '+mbr+'
            . $lis_id . ')', 6);

        $data = $import->fetch_legislator_data('senate', $lis_id);
        if ($data == false) {
            $log->put('Error: Could not fetch that senator’s data.', 6);
            continue;
        }

        $errors = false;

        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors = true;
                $log->put('Required ' . $field . ' is missing for ' . $data['name_formatted']
                    . '.', 6);
            }
        }

        if ($errors == false) {
            /*
             * If there's a photo URL, save it in a separate variable and remove it from the
             * legislator data, because the URL doesn't get inserted into the database.
             */
            if (isset($data['photo_url'])) {
                $photo_url = $data['photo_url'];
                $unset($data['photo_url']);
            }

            if ($import->add_legislator($data) == false) {
                $log->put('Could not add ' . $data['name_formatted'] . ' to the system', 6);
                continue;
            }

            $photo_success = $import->fetch_photo($photo_url, $data['shortname']);
            if ($photo_success == false) {
                $log->put('Could not retrieve photo of ' . $data['name_formatted'], 4);
            } else {
                $log->put('Photo of ' . $data['name_formatted'] . ' stored at ' . $photo_success
                    . '. You need to manually commit it to the Git repo.', 5);
            }
        } else {
            $log->put('The new record for ' . $data['name_formatted'] . ' was not added to the '
                . 'system, due to missing data.', 6);
            unset($errors);
        }
    }
}

/*
 * Third, see there are any listed delegates or senators who are not in our records.
 */
foreach ($delegates as $lis_id => $name) {
    $match = false;

    foreach ($known_legislators as $known_legislator) {
        /*
         * LIS inconsistently left-pads LIS IDs with 0s, so allow for that possibility
         */
        if ($known_legislator->lis_id == $lis_id || '0' . $known_legislator->lis_id == $lis_id) {
            $match = true;
            continue(2);
        }
    }

    /*
     * If we've found any new delegates, call that up, and scrape their basic data from LIS
     */
    if ($match == false && $name != 'Vacant') {
        $log->put('Found a new delegate: ' . $name . ' (https://virginiageneralassembly.gov/house/members/members.php?ses=' .
            SESSION_YEAR . '&id=' . $lis_id . ')', 6);
        $data = $import->fetch_legislator_data('house', $lis_id);

        $required_fields = array(
            'name_formal',
            'name',
            'name_formatted',
            'shortname',
            'chamber',
            'district_id',
            'date_started',
            'party',
            'lis_id',
            'email'
        );

        $errors = false;

        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors = true;
                $log->put('Error: Required ' . $field . ' is missing for ' . $data['name_formatted']
                    . ', so they couldn’t be added to the system.', 6);
            }
        }

        if ($errors == false) {
            $log->put('All required data found for ' . $data['name_formatted'], 4);

            /*
             * If there's a photo URL, save it in a separate variable and remove it from the
             * legislator data, because the URL doesn't get inserted into the database.
             */
            if (isset($data['photo_url'])) {
                $photo_url = $data['photo_url'];
                unset($data['photo_url']);
            }

            if ($import->add_legislator($data) == false) {
                $log->put('Could not add ' . $data['name_formatted'] . ' to the system', 6);
                continue;
            }

            $log->put('Added ' . $data['name_formatted'] . ' to the database', 4);

            $photo_success = $import->fetch_photo($photo_url, $data['shortname']);
            if ($photo_success == false) {
                $log->put('Could not retrieve photo of ' . $data['name_formatted'], 4);
            } else {
                $log->put('Photo of ' . $data['name_formatted'] . ' stored at ' . $photo_success
                    . '. You need to manually commit it to the Git repo.', 5);
            }
        } else {
            $log->put('The new record for ' . $data['name_formatted'] . ' was not added to the '
                . 'system, due to missing data.', 6);
            unset($errors);
        }
    }
}
