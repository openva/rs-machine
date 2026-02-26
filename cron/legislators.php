<?php

/*
 * Connect to the database
 */
$database = new Database();
$db = $database->connect();
global $db;
$GLOBALS['dbh'] = $db;

/*
 * Instantiate the logging class
 */
$log = new Log();

/*
 * Instantiate Memcached
 */
if (MEMCACHED_SERVER != '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
} else {
    $mc = null;
}

$import = new Import($log);

if (!function_exists('merge_legislator_data_sets')) {
    function merge_legislator_data_sets(array $primary, array $additional)
    {
        foreach ($additional as $key => $value) {
            if (!array_key_exists($key, $primary) || $primary[$key] === null || $primary[$key] === '') {
                $primary[$key] = $value;
            }
        }

        return $primary;
    }
}

if (!function_exists('build_legacy_lis_id')) {
    function build_legacy_lis_id($chamber, $lis_id)
    {
        $digits = preg_replace('/[^0-9]/', '', (string)$lis_id);
        if ($digits === '') {
            return $lis_id;
        }

        if (strtolower($chamber) === 'senate') {
            return 'S' . str_pad($digits, 4, '0', STR_PAD_LEFT);
        }

        return 'H' . str_pad($digits, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('rebuild_name_formatted')) {
    function rebuild_name_formatted(array $legislator)
    {
        // Extract components
        $chamber = $legislator['chamber'] ?? '';
        $party = strtoupper(trim($legislator['party'] ?? ''));
        $place = trim($legislator['place'] ?? '');
        $district_number = $legislator['district_number'] ?? $legislator['district_id'] ?? '';

        // Extract name from existing name_formatted or construct from components
        if (!empty($legislator['name_formatted'])) {
            // Extract just the name portion (before the party designation)
            if (preg_match('/^((?:Sen\.|Del\.)\s+[^(]+)/', $legislator['name_formatted'], $matches)) {
                $name_part = trim($matches[1]);
            } else {
                $name_part = $legislator['name_formatted'];
            }
        } else {
            $prefix = ($chamber === 'senate') ? 'Sen.' : 'Del.';
            $name_part = $prefix . ' ' . ($legislator['name_formal'] ?? '');
        }

        // Build the suffix (place or district number)
        $suffix = '';
        if (!empty($place)) {
            // Use place if available
            $suffix = $place;
        } elseif (!empty($district_number)) {
            // Fall back to district number
            $suffix = $district_number;
        }

        // Construct the formatted name
        $formatted = trim($name_part);
        if ($party === '') {
            $party = '?';
        }

        if (!empty($suffix)) {
            $formatted .= ' (' . $party . '-' . $suffix . ')';
        } else {
            $formatted .= ' (' . $party . ')';
        }

        return $formatted;
    }
}

/*
 * Retrieve a list of all active delegates' names and IDs. Though that's not *quite* right.
 * Within a couple of weeks of the election, the House's website pretends that the departing
 * delegates are already out office. New delegates are listed, departing ones are gone. To
 * avoid two solid months of errors, instead we get a list of delegates with no end date.
 */
$sql = 'SELECT
            p.name,
            t.chamber,
            t.lis_id,
            t.date_ended
        FROM terms t
        INNER JOIN people p ON p.id = t.person_id
        WHERE t.chamber = "house"
            AND t.date_ended IS NULL';
$stmt = $db->prepare($sql);
$stmt->execute();
$known_legislators = $stmt->fetchAll(PDO::FETCH_OBJ);

/*
 * Now get a list of senators. The senate doesn't change their list of members until the day
 * that a new session starts, so we need to use a slightly different query for them.
 */
$sql = 'SELECT
            p.name,
            t.chamber,
            t.lis_id,
            t.date_ended
        FROM terms t
        INNER JOIN people p ON p.id = t.person_id
        WHERE t.chamber = "senate"
            AND (
                t.date_ended IS NULL
                OR
                t.date_ended >= NOW())';
$stmt = $db->prepare($sql);
$stmt->execute();
$known_legislators = array_merge($known_legislators, $stmt->fetchAll(PDO::FETCH_OBJ));

foreach ($known_legislators as &$known_legislator) {
    $digits = preg_replace('/[^0-9]/', '', (string)$known_legislator->lis_id);
    if ($digits === '') {
        continue;
    }

    if ($known_legislator->chamber == 'senate') {
        $known_legislator->lis_id = 'S' . str_pad($digits, 4, '0', STR_PAD_LEFT);
    } elseif ($known_legislator->chamber == 'house') {
        $known_legislator->lis_id = 'H' . str_pad($digits, 4, '0', STR_PAD_LEFT);
    }
}

$log->put('Loaded ' . count($known_legislators) . ' legislators from local database.', 1);
if (IN_SESSION == true && count($known_legislators) > 140) {
    $log->put('There are ' . count($known_legislators) . ' legislators in the database—too many.', 5);
}

/*
 * Get senators. Their Senate ID (e.g., "S0100") is the key, their name is the value.
 */
$senate_members = $import->fetch_active_members('senate');
$senators = array();
foreach ($senate_members as $member) {
    if (empty($member['lis_id'])) {
        continue;
    }
    $lis_id = 'S' . str_pad($member['lis_id'], 4, '0', STR_PAD_LEFT);
    $senators[$lis_id] = $member['name_formal'] ?? $member['name'] ?? $member['name_formatted'] ?? $lis_id;
}

$log->put('Retrieved ' . count($senators) . ' senators from LIS API.', 1);

if (count($senators) < 35) {
    $log->put('Too few senators were found to be plausible. Abandoning efforts.', 5);
    return;
}

/*
 * Get delegates. Their House ID (e.g., "H0200") is the key, their name is the value.
 */
$house_members = $import->fetch_active_members('house');
$delegates = array();
foreach ($house_members as $member) {
    if (empty($member['lis_id'])) {
        continue;
    }
    $lis_id = 'H' . str_pad($member['lis_id'], 4, '0', STR_PAD_LEFT);
    $delegates[$lis_id] = $member['name_formal'] ?? $member['name'] ?? $member['name_formatted'] ?? $lis_id;
}

$log->put('Retrieved ' . count($delegates) . ' delegates from LIS API.', 1);

if (count($delegates) < 90) {
    $log->put('Since too few delegates were found to be plausible, abandoning efforts.', 5);
    return;
}

/*
 * Update legislators' records periodically
 *
 * Select a handful of currently-serving legislators and update their records based on a re-query
 * of LIS et al. To avoid abusing the legislature's website, we select a small subset of
 * legislators to update each time, so it may be a few days before a given legislator's record
 * picks up changes.
 */
$sql = 'SELECT
            t.id,
            p.name,
            t.chamber,
            t.lis_id
        FROM terms t
        INNER JOIN people p ON p.id = t.person_id
        WHERE t.date_ended IS NULL
        ORDER BY t.date_modified ASC
        LIMIT 10';
$stmt = $db->prepare($sql);
$stmt->execute();
$legislators = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($legislators as &$legislator) {
    $api_data = $import->fetch_legislator_data_api($legislator->chamber, $legislator->lis_id);
    if ($api_data === false) {
        $log->put('Error: Could not refresh API data for ' . $legislator->name . '.', 5);
        continue;
    }

    $legacy_lis_id = build_legacy_lis_id($legislator->chamber, $legislator->lis_id);
    $legacy_data = $import->fetch_legislator_data($legislator->chamber, $legacy_lis_id);
    if (is_array($legacy_data)) {
        $api_data = merge_legislator_data_sets($api_data, $legacy_data);
    }

    // Rebuild name_formatted after merge to use the best available place name
    $api_data['name_formatted'] = rebuild_name_formatted($api_data);

    $api_data['id'] = $legislator->id;
    $import->update_legislator($api_data);

    /*
     * Remove this legislator's cached record
     */
    if ($mc instanceof Memcached) {
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
            try {
                $legislator_still_listed = $import->legislator_in_lis($id, $known_legislator->name);
            } catch (Exception $e) {
                $log->put('Error: Could not verify that Sen. ' . pivot($known_legislator->name)
                    . ' is no longer in office. Error thrown: ' . $e->getMessage(), 5);
                continue;
            }

            if ($legislator_still_listed === true) {
                $log->put('Error: Sen. ' . pivot($known_legislator->name) . ' is missing from '
                    . 'the LIS API roster, but is still listed on the Senate website. They will be kept '
                    . 'active until they are removed from the website.', 5);
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
            try {
                $legislator_still_listed = $import->legislator_in_lis($id, $known_legislator->name);
            } catch (Exception $e) {
                $log->put('Error: Could not verify that Del. ' . pivot($known_legislator->name)
                    . ' is no longer in office. Error thrown: ' . $e->getMessage(), 5);
                continue;
            }

            if ($legislator_still_listed === true) {
                $log->put('Error: Del. ' . pivot($known_legislator->name) . ' is missing from '
                    . 'the LIS API roster, but is still listed on the House website. They will be kept '
                    . 'active until they are removed from the website.', 5);
                continue;
            }

            $log->put('Error: Del. ' . pivot($known_legislator->name)
                . ' is no longer in office, but is still listed in the database.', 5);
            if ($import->deactivate_legislator($id) == false) {
                $log->put('Error: ...but they couldn’t be marked as out of office.', 5);
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
            . 'https://apps.senate.virginia.gov/Senator/memberpage.php?id='
            . $lis_id . ')', 6);

        $api_data = $import->fetch_legislator_data_api('senate', $lis_id);
        if ($api_data === false) {
            $log->put('Error: Could not fetch API data for ' . $name . '.', 6);
            continue;
        }

        $legacy_data = $import->fetch_legislator_data('senate', $lis_id);
        if (is_array($legacy_data)) {
            $data = merge_legislator_data_sets($api_data, $legacy_data);
        } else {
            $data = $api_data;
        }

        if ($data == false) {
            $log->put('Error: Could not assemble data for ' . $name . '.', 6);
            continue;
        }

        // Rebuild name_formatted after merge to use the best available place name
        $data['name_formatted'] = rebuild_name_formatted($data);

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
                unset($data['photo_url']);
            } else {
                $photo_url = null;
            }

            $result = $import->add_legislator($data);
            if ($result === false) {
                $log->put('Could not add ' . $data['name_formatted'] . ' to the system', 6);
                continue;
            } elseif ($result === null) {
                continue;
            }

            if (!empty($photo_url)) {
                $photo_success = $import->fetch_photo($photo_url, $data['shortname']);
                if ($photo_success == false) {
                    $log->put('Could not retrieve photo of ' . $data['name_formatted'], 4);
                } else {
                    $photo_path = $photo_success;
                    if (substr($photo_path, 0, 1) !== '/') {
                        $photo_path = getcwd() . '/' . $photo_path;
                    }
                    $log->put('Photo of ' . $data['name_formatted'] . ' stored at ' . $photo_path
                        . '. You need to manually commit it to the Git repo.', 5);
                }
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

        $api_data = $import->fetch_legislator_data_api('house', $lis_id);
        if ($api_data === false) {
            $log->put('Error: Could not fetch API data for ' . $name . '.', 6);
            continue;
        }

        $legacy_data = $import->fetch_legislator_data('house', $lis_id);
        if (is_array($legacy_data)) {
            $data = merge_legislator_data_sets($api_data, $legacy_data);
        } else {
            $data = $api_data;
        }

        // Rebuild name_formatted after merge to use the best available place name
        $data['name_formatted'] = rebuild_name_formatted($data);

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
            $log->put('All required data found for ' . $data['name_formatted'], 2);

            /*
             * If there's a photo URL, save it in a separate variable and remove it from the
             * legislator data, because the URL doesn't get inserted into the database.
             */
            if (isset($data['photo_url'])) {
                $photo_url = $data['photo_url'];
                unset($data['photo_url']);
            } else {
                $photo_url = null;
            }

            $result = $import->add_legislator($data);
            if ($result === false) {
                $log->put('Could not add ' . $data['name_formatted'] . ' to the system', 6);
                continue;
            } elseif ($result === null) {
                continue;
            }

            $log->put('Added ' . $data['name_formatted'] . ' to the database', 4);

            if (!empty($photo_url)) {
                $photo_success = $import->fetch_photo($photo_url, $data['shortname']);
                if ($photo_success == false) {
                    $log->put('Could not retrieve photo of ' . $data['name_formatted'], 4);
                } else {
                    $photo_path = $photo_success;
                    if (substr($photo_path, 0, 1) !== '/') {
                        $photo_path = getcwd() . '/' . $photo_path;
                    }
                    $log->put('Photo of ' . $data['name_formatted'] . ' stored at ' . $photo_path
                        . '. You need to manually commit it to the Git repo.', 5);
                }
            }
        } else {
            $log->put('The new record for ' . $data['name_formatted'] . ' was not added to the '
                . 'system, due to missing data.', 6);
            unset($errors);
        }
    }
}
