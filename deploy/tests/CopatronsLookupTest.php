<?php

use PHPUnit\Framework\TestCase;
use RsMachine\Copatrons;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * Whom copatrons.php looks LIS member IDs up among: everyone who served during the session.
 */
class CopatronsLookupTest extends TestCase
{
    /** A session ID that no real session has. */
    private const SESSION_ID = 250;
    private const SESSION_START = '2001-01-10';

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

        $dbh->prepare('INSERT INTO sessions (id, year, date_started, notes, date_created)
            VALUES (:id, 2001, :date_started, "test", NOW())')
            ->execute([':id' => self::SESSION_ID, ':date_started' => self::SESSION_START]);
    }

    protected function tearDown(): void
    {
        if (isset($this->dbh)) {
            $this->cleanUp();
        }
    }

    public function testACurrentLegislatorIsFound(): void
    {
        $person = $this->legislator('zzcopatron1', [['house', 9001, '1995-01-10', null]]);

        $this->assertSame($person, $this->lookUp()['H9001'] ?? null);
    }

    /**
     * The bug: someone who left after the session started lost every copatronage that session.
     */
    public function testALegislatorWhoLeftDuringTheSessionIsFound(): void
    {
        $person = $this->legislator('zzcopatron2', [['senate', 9002, '1995-01-10', '2001-06-30']]);

        $this->assertSame($person, $this->lookUp()['S9002'] ?? null);
    }

    public function testALegislatorWhoLeftBeforeTheSessionIsNot(): void
    {
        $this->legislator('zzcopatron3', [['house', 9003, '1995-01-10', '2000-06-30']]);

        $this->assertArrayNotHasKey('H9003', $this->lookUp());
    }

    /**
     * A delegate elected to the Senate during the session copatroned bills under both IDs.
     */
    public function testBothOfAChamberChangersIdsAreFound(): void
    {
        $person = $this->legislator('zzcopatron4', [
            ['house', 9004, '1995-01-10', '2001-01-31'],
            ['senate', 9005, '2001-02-01', null],
        ]);

        $lookup = $this->lookUp();
        $this->assertSame($person, $lookup['H9004'] ?? null);
        $this->assertSame($person, $lookup['S9005'] ?? null);
    }

    public function testATermThatHasntStartedIsNot(): void
    {
        $this->legislator('zzcopatron5', [['house', 9006, '2099-01-10', null]]);

        $this->assertArrayNotHasKey('H9006', $this->lookUp());
    }

    public function testMemberIdsArePadded(): void
    {
        $this->assertSame('H0173', Copatrons::memberId('house', 173));
        $this->assertSame('S0099', Copatrons::memberId('senate', 99));
    }

    /** @return array<string,array{legislator_id:int,person_id:int}> */
    private function lookUp(): array
    {
        return Copatrons::legislators($this->dbh, self::SESSION_ID);
    }

    /**
     * @param array<int,array{0:string,1:int,2:string,3:?string}> $terms Chamber, LIS number,
     *        start, end.
     * @return array{legislator_id:int,person_id:int}
     */
    private function legislator(string $shortname, array $terms): array
    {
        $this->dbh->prepare('INSERT INTO people (shortname, name, name_formal, date_created)
            VALUES (:shortname, "Legislator, Test", "Test Legislator", NOW())')
            ->execute([':shortname' => $shortname]);
        $person_id = (int) $this->dbh->lastInsertId();

        foreach ($terms as [$chamber, $lis_id, $started, $ended]) {
            $this->dbh->prepare('INSERT INTO terms
                    (person_id, name_formatted, lis_shortname, lis_id, chamber, district_id, date_started,
                     date_ended, partisanship, date_created, date_modified)
                VALUES (:person_id, "Test Legislator", "test", :lis_id, :chamber, 1, :started, :ended, 0,
                    NOW(), NOW())')
                ->execute([':person_id' => $person_id, ':lis_id' => $lis_id, ':chamber' => $chamber,
                    ':started' => $started, ':ended' => $ended]);
        }

        // As representatives_table.php does, with an ID that isn't the person's.
        $this->dbh->prepare('INSERT INTO representatives
                (id, name_formal, name, name_formatted, shortname, chamber, district_id, date_started, date_created)
            VALUES (:id, "Test Legislator", "Legislator, Test", "Test Legislator", :shortname, "house", 1,
                "1995-01-10", NOW())')
            ->execute([':id' => 60000 + (int) substr($shortname, -1), ':shortname' => $shortname]);

        return ['legislator_id' => 60000 + (int) substr($shortname, -1), 'person_id' => $person_id];
    }

    private function cleanUp(): void
    {
        $this->dbh->exec('DELETE t FROM terms t JOIN people p ON p.id = t.person_id
            WHERE p.shortname LIKE "zzcopatron%"');
        $this->dbh->exec('DELETE FROM people WHERE shortname LIKE "zzcopatron%"');
        $this->dbh->exec('DELETE FROM representatives WHERE shortname LIKE "zzcopatron%"');
        $this->dbh->exec('DELETE FROM sessions WHERE id = ' . self::SESSION_ID);
    }
}
