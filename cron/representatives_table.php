<?php

declare(strict_types=1);

/**
 * Populate the legacy representatives table from the new people + terms tables.
 *
 * When invoked via cron/update.php, the surrounding framework will already have loaded the
 * necessary includes and configured logging. The fallbacks below allow the script to be executed
 * directly from the CLI for one-off migrations.
 */

if (!class_exists('Log')) {
    require_once __DIR__ . '/../includes/settings.inc.php';
    require_once __DIR__ . '/../includes/functions.inc.php';
    require_once __DIR__ . '/../includes/vendor/autoload.php';
}

if (!isset($log) || !($log instanceof Log)) {
    $log = new Log();
}

$database = new Database();
$pdo = $database->connect();

if (!($pdo instanceof PDO)) {
    $log->put('representatives_table: Could not establish PDO connection.', 5);
    return;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$sql = <<<SQL
    SELECT
        t.id,
        t.person_id,
        t.name_formatted,
        t.lis_shortname,
        t.lis_id,
        t.chamber,
        t.party,
        t.district_Id AS district_id,
        t.date_started,
        t.date_ended,
        t.sbe_id,
        t.email,
        t.url,
        t.rss_url,
        t.place,
        t.longitude,
        t.latitude,
        t.phone_district,
        t.phone_richmond,
        t.address_district,
        t.address_richmond,
        t.date_created AS term_date_created,
        t.date_modified AS term_date_modified,
        p.shortname,
        p.name,
        p.name_formal,
        p.birthday,
        p.race,
        p.sex,
        p.bio,
        p.date_created AS person_date_created,
        p.date_modified AS person_date_modified
    FROM terms t
    INNER JOIN people p ON p.id = t.person_id
    ORDER BY t.id
SQL;

$rows = $pdo->query($sql)->fetchAll();

if (empty($rows)) {
    $log->put('representatives_table: No terms/people rows found; nothing to migrate.', 2);
    return;
}

$insert = $pdo->prepare(
    'INSERT INTO representatives
        (`id`,`name_formal`,`name`,`name_formatted`,`shortname`,`lis_shortname`,`chamber`,`district_id`,
         `date_started`,`date_ended`,`party`,`bio`,`birthday`,`race`,`sex`,`notes`,
         `phone_district`,`phone_richmond`,`address_district`,`address_richmond`,
         `email`,`url`,`rss_url`,`twitter`,`sbe_id`,`lis_id`,`place`,`longitude`,
         `latitude`,`contributions`,`partisanship`,`date_modified`,`date_created`)
     VALUES
        (:id,:name_formal,:name,:name_formatted,:shortname,:lis_shortname,:chamber,:district_id,
         :date_started,:date_ended,:party,:bio,:birthday,:race,:sex,:notes,
         :phone_district,:phone_richmond,:address_district,:address_richmond,
         :email,:url,:rss_url,:twitter,:sbe_id,:lis_id,:place,:longitude,
         :latitude,:contributions,:partisanship,:date_modified,:date_created)
     ON DUPLICATE KEY UPDATE
         `name_formal` = VALUES(`name_formal`),
         `name` = VALUES(`name`),
         `name_formatted` = VALUES(`name_formatted`),
         `shortname` = VALUES(`shortname`),
         `lis_shortname` = VALUES(`lis_shortname`),
         `chamber` = VALUES(`chamber`),
         `district_id` = VALUES(`district_id`),
         `date_started` = VALUES(`date_started`),
         `date_ended` = VALUES(`date_ended`),
         `party` = VALUES(`party`),
         `birthday` = VALUES(`birthday`),
         `race` = VALUES(`race`),
         `sex` = VALUES(`sex`),
         `email` = VALUES(`email`),
         `url` = VALUES(`url`),
         `rss_url` = VALUES(`rss_url`),
         `sbe_id` = VALUES(`sbe_id`),
         `lis_id` = VALUES(`lis_id`),
         `place` = VALUES(`place`),
         `longitude` = VALUES(`longitude`),
         `latitude` = VALUES(`latitude`),
         `date_modified` = VALUES(`date_modified`),
         `date_created` = VALUES(`date_created`)'
);

$inTransaction = false;
$count = 0;

try {
    $pdo->beginTransaction();
    $inTransaction = true;

    foreach ($rows as $row) {
        $dateStarted = normalizeDate($row['date_started']) ?? fallbackDate($row['term_date_created'] ?? $row['person_date_created']);

        $insert->execute([
            ':id' => (int)$row['id'],
            ':name_formal' => $row['name_formal'],
            ':name' => $row['name'],
            ':name_formatted' => $row['name_formatted'],
            ':shortname' => $row['shortname'],
            ':lis_shortname' => $row['lis_shortname'],
            ':chamber' => $row['chamber'],
            ':district_id' => (int)$row['district_id'],
            ':date_started' => $dateStarted,
            ':date_ended' => normalizeDate($row['date_ended']),
            ':party' => $row['party'],
            ':bio' => $row['bio'],
            ':birthday' => normalizeDate($row['birthday']),
            ':race' => normalizeEnum($row['race'], 'white'),
            ':sex' => normalizeEnum($row['sex'], 'male'),
            ':notes' => null,
            ':phone_district' => normalizeNullable($row['phone_district']),
            ':phone_richmond' => normalizeNullable($row['phone_richmond']),
            ':address_district' => normalizeNullable($row['address_district']),
            ':address_richmond' => normalizeNullable($row['address_richmond']),
            ':email' => normalizeNullable($row['email']),
            ':url' => normalizeNullable($row['url']),
            ':rss_url' => normalizeNullable($row['rss_url']),
            ':twitter' => null,
            ':sbe_id' => normalizeNullable($row['sbe_id']),
            ':lis_id' => (int)($row['lis_id'] ?? 0),
            ':place' => normalizeNullable($row['place']),
            ':longitude' => normalizeFloat($row['longitude']),
            ':latitude' => normalizeFloat($row['latitude']),
            ':contributions' => null,
            ':partisanship' => null,
            ':date_modified' => normalizeTimestamp($row['term_date_modified'] ?? $row['person_date_modified']),
            ':date_created' => normalizeTimestamp($row['term_date_created'] ?? $row['person_date_created']),
        ]);

        $count++;
    }

    $pdo->commit();
    $inTransaction = false;

    $log->put(sprintf('representatives_table: Migrated %d rows from people/terms into representatives.', $count), 2);
} catch (Throwable $exception) {
    if ($inTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $log->put('representatives_table: Migration failed - ' . $exception->getMessage(), 5);
    return;
}

function normalizeDate($value): ?string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return substr($value, 0, 10);
    }
    return null;
}

function normalizeNullable($value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function normalizeEnum($value, string $default): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return $default;
    }
    return $value;
}

function normalizeFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return (float)$value;
}

function normalizeTimestamp($value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return date('Y-m-d H:i:s');
    }
    return $value;
}

function fallbackDate(?string $source): string
{
    $source = trim((string)$source);
    if ($source !== '') {
        $candidate = substr($source, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            return $candidate;
        }
    }
    return '1900-01-01';
}
