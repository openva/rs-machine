<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * cron/representatives_table.php, which gives each legislator a row in representatives whose ID
 * is the person's ID.
 */
class RepresentativesTableTest extends TestCase
{
    private PDO $dbh;

    protected function setUp(): void
    {
        $dbh = @(new Database())->connect();
        if (!($dbh instanceof PDO)) {
            $this->markTestSkipped('Database not available');
        }
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $dbh;
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->dbh)) {
            $this->cleanUp();
        }
    }

    /**
     * Rows were given term IDs, some of which are other people's IDs. Here, each of two
     * legislators' rows has the other's person ID.
     */
    public function testEachRowGetsItsPersonsId(): void
    {
        $first = $this->person('zzrep1');
        $second = $this->person('zzrep2');
        $this->representative('zzrep1', $second);
        $this->representative('zzrep2', $first);

        $this->runScript();

        $this->assertSame(
            ['zzrep1' => $first, 'zzrep2' => $second],
            array_map('intval', $this->dbh->query('SELECT shortname, id FROM representatives
                WHERE shortname LIKE "zzrep%" ORDER BY shortname')->fetchAll(PDO::FETCH_KEY_PAIR))
        );
        $this->assertSame('Del. Test zzrep2', $this->dbh->query('SELECT name_formatted FROM representatives
            WHERE id = ' . $second)->fetchColumn(), 'Each row has its own person\'s term.');
    }

    private function runScript(): void
    {
        $log = new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        };
        require __DIR__ . '/../../cron/representatives_table.php';
    }

    private function person(string $shortname): int
    {
        $this->dbh->prepare('INSERT INTO people (shortname, name, name_formal, date_created)
            VALUES (:shortname, "Test, Legislator", "Legislator Test", NOW())')->execute([':shortname' => $shortname]);
        $person_id = (int) $this->dbh->lastInsertId();
        $this->dbh->prepare('INSERT INTO terms
                (person_id, name_formatted, lis_shortname, lis_id, chamber, district_id, date_started,
                 partisanship, date_created, date_modified)
            VALUES (:person_id, :name_formatted, "test", 9999, "house", 1, "2026-01-14", 0, NOW(), NOW())')
            ->execute([':person_id' => $person_id, ':name_formatted' => 'Del. Test ' . $shortname]);

        return $person_id;
    }

    private function representative(string $shortname, int $id): void
    {
        $this->dbh->prepare('INSERT INTO representatives
                (id, name_formal, name, name_formatted, shortname, chamber, district_id, date_started, date_created)
            VALUES (:id, "Legislator Test", "Test, Legislator", "Old", :shortname, "house", 1, "2026-01-14", NOW())')
            ->execute([':id' => $id, ':shortname' => $shortname]);
    }

    private function cleanUp(): void
    {
        $this->dbh->exec('DELETE t FROM terms t JOIN people p ON p.id = t.person_id WHERE p.shortname LIKE "zzrep%"');
        $this->dbh->exec('DELETE FROM representatives WHERE shortname LIKE "zzrep%"');
        $this->dbh->exec('DELETE FROM people WHERE shortname LIKE "zzrep%"');
    }
}
