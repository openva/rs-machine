<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

if (!class_exists('NullLog', false)) {
    /**
     * Silent logger for test isolation.
     */
    class NullLog extends Log
    {
        public function put($message, $level = 3)
        {
            return true;
        }

        public function filesystem($message)
        {
            return true;
        }
    }
}

/**
 * Import stub whose term inserts fail, to show that a person isn't left behind without a term.
 */
class ImportFailingTermStub extends Import
{
    protected function insertTerm($person_id, $normalized_lis_id, array $legislator)
    {
        return false;
    }
}

/**
 * Adding a legislator must never create a person without a term. Persons without terms are
 * listed by the website as current legislators, with shortnames that have no photo.
 */
class LegislatorAddTest extends TestCase
{
    /** IDs well above any real person, term, or LIS ID. */
    private const PERSON_ID = 65000;
    private const OTHER_PERSON_ID = 65001;
    private const TERM_ID = 65000;
    private const OTHER_TERM_ID = 65001;
    private const LIS_ID = 65000;
    private const OTHER_LIS_ID = 65001;

    /** Shortnames used by the fixtures, to be cleaned up. */
    private const SHORTNAMES = ['zzwdtestwiley', 'zztestwiley', 'zztestnew'];

    /** @var PDO */
    private $pdo;

    protected function setUp(): void
    {
        try {
            $database = new Database();
            $this->pdo = $database->connect();
        } catch (Throwable $e) {
            $this->pdo = false;
        }
        if (!$this->pdo instanceof PDO) {
            $this->markTestSkipped('No database connection is available.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $GLOBALS['dbh'] = $this->pdo;
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            $this->cleanUp();
        }
    }

    /**
     * The LIS API calls William D. Wiley "Bill Wiley", so the generated shortname doesn't match
     * the stored one. Seeing him as new must not create a second person for him.
     */
    public function testExistingActiveTermCreatesNoPerson(): void
    {
        $this->insertPerson(self::PERSON_ID, 'zzwdtestwiley');
        $this->insertTerm(self::TERM_ID, self::PERSON_ID, self::LIS_ID, 'house', null);

        $import = new Import(new NullLog());
        $result = $import->add_legislator($this->legislator('zztestwiley', self::LIS_ID));

        $this->assertNull($result);
        $this->assertSame(0, $this->countPeople('zztestwiley'));
    }

    /**
     * A returning legislator gets a new term attached to their existing person, found by their
     * LIS ID, even though the generated shortname differs.
     */
    public function testReturningLegislatorIsFoundByLisId(): void
    {
        $this->insertPerson(self::PERSON_ID, 'zzwdtestwiley');
        $this->insertTerm(self::TERM_ID, self::PERSON_ID, self::LIS_ID, 'house', '2020-01-01');

        $import = new Import(new NullLog());
        $result = $import->add_legislator($this->legislator('zztestwiley', self::LIS_ID));

        $this->assertTrue($result);
        $this->assertSame(0, $this->countPeople('zztestwiley'));
        $this->assertSame(2, $this->countTerms(self::PERSON_ID));
    }

    /**
     * A new legislator whose generated shortname belongs to a different former legislator in the
     * same chamber must not be merged into that person.
     */
    public function testShortnameOfDifferentLegislatorIsNotReused(): void
    {
        $this->insertPerson(self::OTHER_PERSON_ID, 'zztestwiley');
        $this->insertTerm(self::OTHER_TERM_ID, self::OTHER_PERSON_ID, self::OTHER_LIS_ID, 'house', '2010-01-01');

        $import = new Import(new NullLog());
        $result = $import->add_legislator($this->legislator('zztestwiley', self::LIS_ID));

        $this->assertFalse($result);
        $this->assertSame(1, $this->countTerms(self::OTHER_PERSON_ID));
        $this->assertSame(0, $this->countTermsForLisId(self::LIS_ID));
    }

    /**
     * A returning legislator's stored details survive an import that doesn't supply them, while
     * the ones it does supply are updated.
     */
    public function testReturningLegislatorKeepsDetailsImportLacks(): void
    {
        $this->insertPerson(self::PERSON_ID, 'zzwdtestwiley');
        $this->pdo->exec(
            'UPDATE people SET birthday = "1960-05-01", race = "white", sex = "male",
                bio = "A stored biography." WHERE id = ' . self::PERSON_ID
        );
        $this->insertTerm(self::TERM_ID, self::PERSON_ID, self::LIS_ID, 'house', '2020-01-01');

        $legislator = $this->legislator('zztestwiley', self::LIS_ID);
        $legislator['sex'] = '';
        $legislator['bio'] = '   ';
        $legislator['race'] = 'Black';

        $import = new Import(new NullLog());
        $this->assertTrue($import->add_legislator($legislator));

        $stmt = $this->pdo->prepare(
            'SELECT name, name_formal, birthday, race, sex, bio FROM people WHERE id = :id'
        );
        $stmt->execute([':id' => self::PERSON_ID]);
        $this->assertSame(
            [
                'name' => 'Testwiley, Bill',
                'name_formal' => 'Bill Testwiley',
                'birthday' => '1960-05-01',
                'race' => 'black',
                'sex' => 'male',
                'bio' => 'A stored biography.',
            ],
            $stmt->fetch(PDO::FETCH_ASSOC)
        );
    }

    /**
     * A legislator moving to the other chamber gets a new LIS ID, and is found by shortname.
     */
    public function testLegislatorChangingChambersIsFoundByShortname(): void
    {
        $this->insertPerson(self::PERSON_ID, 'zztestwiley');
        $this->insertTerm(self::TERM_ID, self::PERSON_ID, self::OTHER_LIS_ID, 'house', '2024-01-01');

        $legislator = $this->legislator('zztestwiley', self::LIS_ID);
        $legislator['chamber'] = 'senate';

        $import = new Import(new NullLog());
        $this->assertTrue($import->add_legislator($legislator));
        $this->assertSame(2, $this->countTerms(self::PERSON_ID));
    }

    /**
     * When the term can't be inserted, the new person is rolled back.
     */
    public function testFailedTermLeavesNoPerson(): void
    {
        $import = new ImportFailingTermStub(new NullLog());
        $result = $import->add_legislator($this->legislator('zztestnew', self::LIS_ID));

        $this->assertFalse($result);
        $this->assertSame(0, $this->countPeople('zztestnew'));
    }

    /**
     * A genuinely new legislator gets a person and a term.
     */
    public function testNewLegislatorGetsPersonAndTerm(): void
    {
        $import = new Import(new NullLog());
        $result = $import->add_legislator($this->legislator('zztestnew', self::LIS_ID));

        $this->assertTrue($result);
        $this->assertSame(1, $this->countPeople('zztestnew'));
        $this->assertSame(1, $this->countTermsForLisId(self::LIS_ID));
    }

    private function legislator(string $shortname, int $lis_id): array
    {
        return [
            'name_formal' => 'Bill Testwiley',
            'name' => 'Testwiley, Bill',
            'name_formatted' => 'Del. Bill Testwiley (R-Test)',
            'shortname' => $shortname,
            'chamber' => 'house',
            'district_id' => 1,
            'date_started' => '2026-01-14',
            'party' => 'R',
            'lis_id' => 'H' . $lis_id,
            'email' => 'test@example.com',
        ];
    }

    private function insertPerson(int $id, string $shortname): void
    {
        $this->pdo->prepare(
            'INSERT INTO people (id, shortname, name, name_formal, date_created)
            VALUES (:id, :shortname, "Testwiley, William", "William D. Testwiley", NOW())'
        )->execute([':id' => $id, ':shortname' => $shortname]);
    }

    private function insertTerm(int $id, int $person_id, int $lis_id, string $chamber, ?string $date_ended): void
    {
        $this->pdo->prepare(
            'INSERT INTO terms (id, person_id, name_formatted, lis_shortname, lis_id, chamber, party,
                district_id, partisanship, date_started, date_ended, date_created)
            VALUES (:id, :person_id, "Del. William Testwiley (R-Test)", "testwiley", :lis_id,
                :chamber, "R", 1, 0, "2018-01-10", :date_ended, NOW())'
        )->execute([
            ':id' => $id,
            ':person_id' => $person_id,
            ':lis_id' => $lis_id,
            ':chamber' => $chamber,
            ':date_ended' => $date_ended,
        ]);
    }

    private function countPeople(string $shortname): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM people WHERE shortname = :shortname');
        $stmt->execute([':shortname' => $shortname]);
        return (int)$stmt->fetchColumn();
    }

    private function countTerms(int $person_id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM terms WHERE person_id = :person_id');
        $stmt->execute([':person_id' => $person_id]);
        return (int)$stmt->fetchColumn();
    }

    private function countTermsForLisId(int $lis_id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM terms WHERE lis_id = :lis_id');
        $stmt->execute([':lis_id' => $lis_id]);
        return (int)$stmt->fetchColumn();
    }

    private function cleanUp(): void
    {
        $this->pdo->exec(
            'DELETE FROM terms WHERE lis_id IN (' . self::LIS_ID . ', ' . self::OTHER_LIS_ID . ')
                OR id IN (' . self::TERM_ID . ', ' . self::OTHER_TERM_ID . ')'
        );
        $placeholders = implode(',', array_fill(0, count(self::SHORTNAMES), '?'));
        $this->pdo->prepare('DELETE FROM people WHERE shortname IN (' . $placeholders . ')')
            ->execute(self::SHORTNAMES);
    }
}
