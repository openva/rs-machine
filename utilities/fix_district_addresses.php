<?php

/*
 * Rewrite legislators' stored district office addresses in the form the website displays
 * properly, "street, city, ST ZIP" (see RsMachine\DistrictAddress).
 *
 * legislators.php rewrites current legislators' addresses as it refreshes them, 10 a day, but never
 * former legislators', and representatives_table.php never updates the addresses it has copied to
 * `representatives`. This fixes both tables, and every term. It's safe to rerun.
 *
 * Usage: php utilities/fix_district_addresses.php [--apply]
 *
 * Without --apply, it lists the changes it would make, and makes none.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

use RsMachine\DistrictAddress;

$apply = in_array('--apply', array_slice($argv, 1), true);

$dbh = (new Database())->connect();
if (!$dbh instanceof PDO) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
 * Each table, the person ID its rows belong to (for the website's cache key), and how to update an
 * address without changing the row's date_modified (which legislators.php orders its refreshes by).
 */
$tables = [
    'terms' => [
        'select' => 'SELECT id, person_id, address_district FROM terms WHERE address_district IS NOT NULL',
        'update' => 'UPDATE terms SET address_district = :address, date_modified = date_modified WHERE id = :id',
    ],
    'representatives' => [
        'select' => 'SELECT id, id AS person_id, address_district FROM representatives WHERE address_district IS NOT NULL',
        'update' => 'UPDATE representatives SET address_district = :address, date_modified = date_modified WHERE id = :id',
    ],
];

$mc = null;
if (class_exists('Memcached') && defined('MEMCACHED_SERVER')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, defined('MEMCACHED_PORT') ? MEMCACHED_PORT : 11211);
}

$people = [];
foreach ($tables as $table => $sql) {
    $changes = [];
    foreach ($dbh->query($sql['select'])->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $address = DistrictAddress::normalize($row['address_district']);
        if ($address !== $row['address_district']) {
            $changes[] = $row + ['new' => $address];
        }
    }

    foreach ($changes as $change) {
        echo $table . ' ' . $change['id'] . ': ' . json_encode($change['address_district'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ' → ' . json_encode($change['new'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    if ($apply && $changes !== []) {
        $update = $dbh->prepare($sql['update']);
        $dbh->beginTransaction();
        foreach ($changes as $change) {
            $update->execute([':address' => $change['new'], ':id' => $change['id']]);
            $people[(int) $change['person_id']] = true;
        }
        $dbh->commit();
    }

    echo ($apply ? 'Changed ' : 'Would change ') . count($changes) . ' addresses in ' . $table . ".\n\n";
}

// The website caches each legislator's record by person ID.
if ($mc instanceof Memcached) {
    foreach (array_keys($people) as $person_id) {
        $mc->delete('legislator-' . $person_id);
    }
}

if (!$apply) {
    echo "Nothing was changed. Run with --apply to make these changes.\n";
}
