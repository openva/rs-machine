<?php

declare(strict_types=1);

include_once __DIR__ . '/../../includes/settings.inc.php';
include_once __DIR__ . '/../../includes/functions.inc.php';
include_once __DIR__ . '/../../includes/vendor/autoload.php';

$log = new Log();
$database = new Database();
$pdo = $database->connect();

if (!($pdo instanceof PDO)) {
    echo "Failure: Unable to connect to database.\n";
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

ensurePeopleTable($pdo);
ensureTermsTable($pdo);
ensureRepresentativesNewTable($pdo);

$testId = 64000;
$person = [
    'id' => $testId,
    'shortname' => 'jdoe',
    'name' => 'Jane Doe',
    'name_formal' => 'Jane A. Doe',
    'birthday' => '1980-05-05 00:00:00',
    'race' => 'black',
    'sex' => 'female',
    'bio' => 'Test bio',
    'date_created' => '2023-12-31 08:00:00',
    'date_modified' => '2024-01-04 09:10:11',
];

$term = [
    'id' => $testId,
    'person_id' => $testId,
    'name_formatted' => 'Del. Jane Doe',
    'lis_shortname' => 'JDoe',
    'lis_id' => 4321,
    'chamber' => 'house',
    'party' => 'D',
    'district_id' => 320,
    'date_started' => '',
    'date_ended' => '0000-00-00 00:00:00',
    'sbe_id' => 'SBE-XYZ',
    'email' => 'jane@example.com',
    'url' => 'https://example.com/profile',
    'rss_url' => 'https://example.com/rss',
    'place' => 'Midlothian',
    'longitude' => '-77.5',
    'latitude' => '37.5',
    'phone_district' => ' 8045552222 ',
    'phone_richmond' => '8045559999',
    'address_district' => '123 Main Street, Midlothian, VA 55555',
    'address_richmond' => 'General Assembly Building',
    'date_created' => '2024-01-02 03:04:05',
    'date_modified' => '2024-01-05 06:07:08',
];

cleanupFixtureRows($pdo, $testId);

$exitStatus = 0;
$message = "All representatives_table tests passed\n";

try {
    insertPerson($pdo, $person);
    insertTerm($pdo, $term);

    $projectRoot = realpath(__DIR__ . '/../../');
    $previousCwd = getcwd();
    chdir($projectRoot);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg('cron/representatives_table.php');
    exec($command, $output, $scriptExitCode);
    chdir($previousCwd);

    if ($scriptExitCode !== 0) {
        $message = "Failure: representatives_table.php exited with status {$scriptExitCode}\n"
            . implode("\n", $output) . "\n";
        $exitStatus = 1;
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM representatives WHERE id = :id');
    $stmt->execute([':id' => $testId]);
    $record = $stmt->fetch();

    if (!$record) {
        $message = "Failure: Expected representatives record with ID {$testId}.\n";
        $exitStatus = 1;
        return;
    }

    $expected = [
        'name_formal' => $person['name_formal'],
        'name' => $person['name'],
        'name_formatted' => $term['name_formatted'],
        'shortname' => $person['shortname'],
        'lis_shortname' => $term['lis_shortname'],
        'chamber' => $term['chamber'],
        'district_id' => (int)$term['district_id'],
        'date_started' => '2024-01-02',
        'date_ended' => null,
        'party' => $term['party'],
        'bio' => $person['bio'],
        'birthday' => '1980-05-05',
        'race' => $person['race'],
        'sex' => $person['sex'],
        'notes' => null,
        'phone_district' => '8045552222',
        'phone_richmond' => $term['phone_richmond'],
        'address_district' => '123 Main Street, Midlothian, VA 55555',
        'address_richmond' => $term['address_richmond'],
        'email' => $term['email'],
        'url' => $term['url'],
        'rss_url' => $term['rss_url'],
        'twitter' => null,
        'sbe_id' => $term['sbe_id'],
        'lis_id' => (string)$term['lis_id'],
        'place' => $term['place'],
        'longitude' => (float)$term['longitude'],
        'latitude' => (float)$term['latitude'],
        'contributions' => null,
        'partisanship' => null,
        'date_modified' => $term['date_modified'],
        'date_created' => $term['date_created'],
    ];

    $errors = [];
    foreach ($expected as $column => $value) {
        $actual = array_key_exists($column, $record) ? $record[$column] : null;
        if ($actual !== $value) {
            $errors[] = sprintf(
                '%s mismatch. Expected %s, got %s',
                $column,
                var_export($value, true),
                var_export($actual, true)
            );
        }
    }

    if (!empty($errors)) {
        $message = "Failure: representatives_table migration produced unexpected values:\n";
        foreach ($errors as $error) {
            $message .= "- {$error}\n";
        }
        $exitStatus = 1;
        return;
    }

    // Verify that rerunning the script refreshes existing rows.
    $pdo->prepare('UPDATE representatives SET name_formal = :value WHERE id = :id')
        ->execute([
            ':value' => 'Changed Name',
            ':id' => $testId,
        ]);

    chdir($projectRoot);
    exec($command, $output, $scriptExitCode);
    chdir($previousCwd);

    if ($scriptExitCode !== 0) {
        $message = "Failure: representatives_table.php exited with status {$scriptExitCode} on refresh\n"
            . implode("\n", $output) . "\n";
        $exitStatus = 1;
        return;
    }

    $stmt->execute([':id' => $testId]);
    $refreshedRecord = $stmt->fetch();
    if (!$refreshedRecord || $refreshedRecord['name_formal'] !== $expected['name_formal']) {
        $message = "Failure: representatives_table did not refresh existing name_formal value.\n";
        $exitStatus = 1;
        return;
    }
} finally {
    cleanupFixtureRows($pdo, $testId);
    echo $message;
    exit($exitStatus);
}

function ensurePeopleTable(PDO $pdo): void
{
    if (tableExists($pdo, 'people')) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE people (
            id INT UNSIGNED NOT NULL,
            shortname VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            name_formal VARCHAR(128) NOT NULL,
            birthday VARCHAR(32) NULL,
            race VARCHAR(64) NULL,
            sex VARCHAR(16) NULL,
            bio TEXT NULL,
            date_created DATETIME NOT NULL,
            date_modified DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
    );
}

function ensureTermsTable(PDO $pdo): void
{
    if (tableExists($pdo, 'terms')) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE terms (
            id INT UNSIGNED NOT NULL,
            person_id INT UNSIGNED NOT NULL,
            name_formatted VARCHAR(255) NOT NULL,
            lis_shortname VARCHAR(64) NOT NULL,
            lis_id INT UNSIGNED NULL,
            chamber VARCHAR(16) NOT NULL,
            party VARCHAR(8) NOT NULL,
            district_id INT UNSIGNED NOT NULL,
            date_started VARCHAR(32) NULL,
            date_ended VARCHAR(32) NULL,
            sbe_id VARCHAR(32) NULL,
            email VARCHAR(255) NULL,
            url VARCHAR(255) NULL,
            rss_url VARCHAR(255) NULL,
            place VARCHAR(255) NULL,
            longitude VARCHAR(32) NULL,
            latitude VARCHAR(32) NULL,
            phone_district VARCHAR(32) NULL,
            phone_richmond VARCHAR(32) NULL,
            address_district VARCHAR(255) NULL,
            address_richmond VARCHAR(255) NULL,
            date_created DATETIME NOT NULL,
            date_modified DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
    );
}

function ensureRepresentativesNewTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'representatives_new')) {
        $pdo->exec('CREATE TABLE representatives_new LIKE representatives');
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute([':table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function cleanupFixtureRows(PDO $pdo, int $id): void
{
    $tables = [
        'representatives' => 'id',
        'terms' => 'id',
        'people' => 'id',
    ];

    foreach ($tables as $table => $column) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$column} = :id");
        $stmt->execute([':id' => $id]);
    }
}

function insertPerson(PDO $pdo, array $person): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO people
            (id, shortname, name, name_formal, birthday, race, sex, bio, date_created, date_modified)
         VALUES
            (:id, :shortname, :name, :name_formal, :birthday, :race, :sex, :bio, :date_created, :date_modified)'
    );
    $stmt->execute([
        ':id' => $person['id'],
        ':shortname' => $person['shortname'],
        ':name' => $person['name'],
        ':name_formal' => $person['name_formal'],
        ':birthday' => $person['birthday'],
        ':race' => $person['race'],
        ':sex' => $person['sex'],
        ':bio' => $person['bio'],
        ':date_created' => $person['date_created'],
        ':date_modified' => $person['date_modified'],
    ]);
}

function insertTerm(PDO $pdo, array $term): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO terms
            (id, person_id, name_formatted, lis_shortname, lis_id, chamber, party, district_id,
             date_started, date_ended, sbe_id, email, url, rss_url, place, longitude, latitude,
             phone_district, phone_richmond, address_district, address_richmond, date_created, date_modified)
         VALUES
            (:id, :person_id, :name_formatted, :lis_shortname, :lis_id, :chamber, :party, :district_id,
             :date_started, :date_ended, :sbe_id, :email, :url, :rss_url, :place, :longitude, :latitude,
             :phone_district, :phone_richmond, :address_district, :address_richmond, :date_created, :date_modified)'
    );
    $stmt->execute([
        ':id' => $term['id'],
        ':person_id' => $term['person_id'],
        ':name_formatted' => $term['name_formatted'],
        ':lis_shortname' => $term['lis_shortname'],
        ':lis_id' => $term['lis_id'],
        ':chamber' => $term['chamber'],
        ':party' => $term['party'],
        ':district_id' => $term['district_id'],
        ':date_started' => $term['date_started'],
        ':date_ended' => $term['date_ended'],
        ':sbe_id' => $term['sbe_id'],
        ':email' => $term['email'],
        ':url' => $term['url'],
        ':rss_url' => $term['rss_url'],
        ':place' => $term['place'],
        ':longitude' => $term['longitude'],
        ':latitude' => $term['latitude'],
        ':phone_district' => $term['phone_district'],
        ':phone_richmond' => $term['phone_richmond'],
        ':address_district' => $term['address_district'],
        ':address_richmond' => $term['address_richmond'],
        ':date_created' => $term['date_created'],
        ':date_modified' => $term['date_modified'],
    ]);
}
