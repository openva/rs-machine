<?php

use PHPUnit\Framework\TestCase;
use RsMachine\PlaceNames;
use RsMachine\PlaceTagger;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * RsMachine\PlaceTagger: which bills are checked for places, how they're tagged, and that each one
 * is recorded as checked. These use the database's real tables, with bills of their own (numbered
 * from 990000, so as not to collide with real ones) in sessions of their own, in 2029 and 2030,
 * that are removed afterward. Those are later than any real session, and bills are checked newest
 * first, so a test that checks only as many bills as it created never reaches the real ones. The
 * tests are skipped where the database, its gazetteer, or its bills.places_checked column isn't there.
 */
class PlaceTaggerTest extends TestCase
{
    private const FIRST_ID = 990001;

    /** The test sessions' IDs. The IDs are small, and not used by real sessions (yet). */
    private const SESSION_2029 = 200;
    private const SESSION_2030 = 201;

    private static ?PDO $db = null;

    /** @var int[] */
    private array $bill_ids = [];

    private int $next_id = self::FIRST_ID;

    /** @var string[] Text to give back for each bill, by its number. */
    private array $texts = [];

    /** @var string[][] The places that "GPT" finds in each text, by the text. */
    private array $answers = [];

    /** @var string[] The texts that "GPT" was asked about. */
    private array $asked = [];

    public static function setUpBeforeClass(): void
    {
        try {
            $db = (new Database())->connect();
        } catch (\Throwable $e) {
            return;
        }
        if (!$db instanceof PDO) {
            return;
        }
        $has_column = $db->query(
            "SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'bills' AND column_name = 'places_checked'"
        )->fetchColumn();
        $has_gazetteer = $db->query(
            "SELECT COUNT(*) FROM gazetteer WHERE name IN ('Fairfax County', 'Henrico County', 'Chesterfield County', 'Richmond')"
        )->fetchColumn();
        if ($has_column && $has_gazetteer >= 4) {
            self::$db = $db;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('The database, its gazetteer, or bills.places_checked is not available');
        }
        $this->cleanUp();
        foreach ([self::SESSION_2029 => 2029, self::SESSION_2030 => 2030] as $id => $year) {
            self::$db->prepare(
                'INSERT INTO sessions (id, year, date_started, notes, date_created)
                VALUES (?, ?, ?, "", NOW())'
            )->execute([$id, $year, $year . '-01-10']);
        }
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        self::$db->exec('DELETE FROM bills_places WHERE bill_id >= ' . self::FIRST_ID);
        self::$db->exec('DELETE FROM bills_full_text WHERE bill_id >= ' . self::FIRST_ID);
        self::$db->exec('DELETE FROM bills WHERE id >= ' . self::FIRST_ID);
        self::$db->exec('DELETE FROM sessions WHERE id IN (' . self::SESSION_2029 . ', ' . self::SESSION_2030 . ')');
    }

    /**
     * Add a bill, and return its id. By default, it's from 2030, has text, and hasn't been checked.
     */
    private function bill(string $text, array $options = []): int
    {
        $options += ['session_id' => self::SESSION_2030, 'with_text' => true, 'checked' => null, 'places' => [], 'summary' => null];
        $id = $this->next_id++;
        $number = 'hb' . ($id - self::FIRST_ID + 9001);

        self::$db->prepare(
            'INSERT INTO bills (id, number, session_id, chief_patron_id, summary, date_created, places_checked, date_modified)
            VALUES (?, ?, ?, 16, ?, NOW(), ?, "2025-01-02 03:04:05")'
        )->execute([$id, $number, $options['session_id'], $options['summary'], $options['checked']]);

        self::$db->prepare(
            'INSERT INTO bills_full_text (bill_id, number, session_id, date_introduced, text, date_created)
            VALUES (?, ?, ?, "2025-01-01", ?, NOW())'
        )->execute([$id, $number . 'h1', $options['session_id'], $options['with_text'] ? $text : null]);

        foreach ($options['places'] as $place) {
            self::$db->prepare(
                'INSERT INTO bills_places (bill_id, placename, latitude, longitude) VALUES (?, ?, 1, 2)'
            )->execute([$id, $place]);
        }

        $this->bill_ids[$number] = $id;
        $this->texts[$number] = $text;
        return $id;
    }

    /**
     * Add another version of a bill's text.
     */
    private function version(int $bill_id, string $text, string $date_introduced): void
    {
        self::$db->prepare(
            'INSERT INTO bills_full_text (bill_id, number, session_id, date_introduced, text, date_created)
            VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$bill_id, 'v' . ($bill_id % 1000) . substr($date_introduced, 2, 2) . substr($date_introduced, 5, 2), self::SESSION_2030, $date_introduced, $text]);
    }

    /**
     * A tagger that reads bills' text from the database, as the cron job does.
     */
    private function databaseTagger(): PlaceTagger
    {
        return new PlaceTagger(self::$db, null, function (string $text): ?array {
            $this->asked[] = $text;
            return [];
        });
    }

    private function tagger(): PlaceTagger
    {
        return new PlaceTagger(
            self::$db,
            function (array $bill): ?string {
                return $this->texts[$bill['number']] ?? null;
            },
            function (string $text): ?array {
                $this->asked[] = $text;
                return $this->answers[$text] ?? [];
            }
        );
    }

    /**
     * @return string[] The places that a bill is tagged with.
     */
    private function places(int $bill_id): array
    {
        $stmt = self::$db->prepare('SELECT placename FROM bills_places WHERE bill_id = ? ORDER BY placename');
        $stmt->execute([$bill_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * The IDs of the test bills that would be chosen next, newest first. (Only the test bills are
     * asked for, since they come before any real ones.)
     *
     * @return int[]
     */
    private function chosen(): array
    {
        return array_column($this->tagger()->candidates(count($this->bill_ids)), 'id');
    }

    private function checked(int $bill_id): bool
    {
        $stmt = self::$db->prepare('SELECT places_checked FROM bills WHERE id = ?');
        $stmt->execute([$bill_id]);
        return $stmt->fetchColumn() !== null;
    }

    public function testBillsFromBeforeThe2024SessionAreNotChosen(): void
    {
        $old = $this->bill('Fairfax County.', ['session_id' => 29]);    // 2023
        $current = $this->bill('Fairfax County.');

        // Asking for more than there are, so that the old bill would be chosen if it could be.
        $chosen = array_column($this->tagger()->candidates(10_000), 'id');
        $this->assertContains($current, $chosen);
        $this->assertNotContains($old, $chosen);
    }

    public function testBillsThatHaveBeenCheckedOrHaveNoTextYetAreNotChosen(): void
    {
        $checked = $this->bill('Fairfax County.', ['checked' => '2025-02-01 00:00:00']);
        $no_text = $this->bill('Fairfax County.', ['with_text' => false]);
        $waiting = $this->bill('Fairfax County.');

        $chosen = array_column($this->tagger()->candidates(10_000), 'id');
        $this->assertContains($waiting, $chosen);
        $this->assertNotContains($checked, $chosen);
        $this->assertNotContains($no_text, $chosen);
    }

    public function testNewestBillsAreChosenFirst(): void
    {
        $older = $this->bill('Fairfax County.', ['session_id' => self::SESSION_2029]);
        $first_of_newer = $this->bill('Fairfax County.');
        $second_of_newer = $this->bill('Fairfax County.');

        $this->assertSame([$second_of_newer, $first_of_newer, $older], $this->chosen());
    }

    /**
     * The text comes from the database, not the API, and only what the bill changes is searched. The
     * City of Richmond is in the law that the bill amends, and Henrico County is what it adds.
     */
    public function testTheTextIsReadFromTheDatabaseAndOnlyChangedLanguageIsSearched(): void
    {
        $id = $this->bill('<p class=enactcls>1. That &sect; 1-1 is amended and reenacted as follows:</p>'
            . '<p class=catchln>&sect; 1-1. Courts.</p>'
            . '<p class=section>Venue is the Circuit Court of the City of Richmond <ins>and Henrico County</ins>.</p>');
        $stats = $this->databaseTagger()->run(1, 10);

        $this->assertSame(['Henrico County'], $this->places($id));
        $this->assertSame([], $this->asked, 'GPT wasn\'t asked');
        $this->assertSame(1, $stats['exact']);
    }

    public function testTheSummaryIsSearchedAsWell(): void
    {
        $id = $this->bill('<p class=enactcls>1. That the Department shall report.</p>', ['summary' => 'Adds <b>Fairfax&nbsp;County</b> to the list.']);
        $this->databaseTagger()->run(1, 10);

        $this->assertSame(['Fairfax County'], $this->places($id), 'Tags and entities in a summary don\'t keep a name from matching');
    }

    public function testTheLatestVersionOfTheTextIsTheOneSearched(): void
    {
        $id = $this->bill('<p class=enactcls>1. That the Department shall report on Henrico County.</p>');   // Version dated 2025-01-01
        $this->version($id, '<p class=enactcls>1. That the Department shall report on Fairfax County.</p>', '2025-02-01');
        $this->version($id, '<p class=enactcls>1. That the Department shall report on Roanoke County.</p>', '2024-12-01');
        $this->databaseTagger()->run(1, 10);

        $this->assertSame(['Fairfax County'], $this->places($id));
    }

    public function testABillThatIsntInTheDatabaseHasNoText(): void
    {
        $this->assertNull($this->databaseTagger()->textFromDatabase(['id' => 999999999]));
    }

    public function testACountyNamedPlainlyIsTaggedWithoutAskingGpt(): void
    {
        $id = $this->bill('Imposes a tax in Fairfax County.');
        $stats = $this->tagger()->run(1, 10);

        $this->assertSame([], $this->asked, 'GPT wasn\'t asked');
        $this->assertSame(['Fairfax County'], $this->places($id));
        $this->assertTrue($this->checked($id));
        $this->assertSame(1, $stats['exact']);
        $this->assertSame(0, $stats['model']);
    }

    public function testATagHasTheGazetteersCoordinates(): void
    {
        $id = $this->bill('Imposes a tax in Fairfax County.');
        $this->tagger()->run(1, 10);

        $row = self::$db->query('SELECT latitude, longitude, ST_X(coordinates) AS x, ST_Y(coordinates) AS y
            FROM bills_places WHERE bill_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(38.83, $row['latitude'], 0.01);
        $this->assertLessThan(-77, $row['longitude']);
        $this->assertEqualsWithDelta($row['longitude'], $row['x'], 0.0001, 'The point is (longitude, latitude)');
        $this->assertEqualsWithDelta($row['latitude'], $row['y'], 0.0001);
    }

    /**
     * This used to be re-checked on every run, forever.
     */
    public function testABillThatNamesNoPlacesIsMarkedAsCheckedAnyway(): void
    {
        $id = $this->bill('Relates to the sale of fireworks.');
        $this->tagger()->run(1, 10);

        $this->assertSame([], $this->places($id));
        $this->assertTrue($this->checked($id));
        $this->assertNotContains($id, array_column($this->tagger()->candidates(10_000), 'id'), 'It isn\'t chosen again');
    }

    public function testABillThatNamesPlacesInOtherWaysIsLeftToGpt(): void
    {
        $text = 'Authorizes the Counties of Henrico and Chesterfield to levy a tax.';
        $this->answers[$text] = ['County of Henrico', 'County of Chesterfield'];
        $id = $this->bill($text);
        $stats = $this->tagger()->run(1, 10);

        $this->assertSame([$text], $this->asked);
        $this->assertSame(['Chesterfield County', 'Henrico County'], $this->places($id));
        $this->assertTrue($this->checked($id));
        $this->assertSame(1, $stats['model']);
    }

    /**
     * Exact matching sees all of a long bill; only what's sent to GPT is cut (a 700,000-character
     * bill is more than it accepts, and would fail on every try).
     */
    public function testOnlyTheTextSentToGptIsCut(): void
    {
        $text = 'The Counties of Henrico and Chesterfield. ' . str_repeat('x ', 150000) . 'Fairfax County.';
        $this->answers[PlaceNames::forModel($text)] = ['County of Henrico', 'County of Chesterfield'];
        $id = $this->bill($text);
        $this->tagger()->run(1, 10);

        $this->assertCount(1, $this->asked);
        $this->assertSame(PlaceNames::MODEL_MAX_CHARS, mb_strlen($this->asked[0]), 'GPT got the first 100,000 characters');
        $this->assertSame(['Chesterfield County', 'Fairfax County', 'Henrico County'], $this->places($id),
            'Fairfax County is past the cut, but exact matching found it');
    }

    public function testWhatWasFoundExactlyIsKeptWhenGptFindsMore(): void
    {
        $text = 'Fairfax County, and also the Counties of Henrico and Chesterfield.';
        $this->answers[$text] = ['County of Henrico'];   // It misses the others
        $id = $this->bill($text);
        $this->tagger()->run(1, 10);

        $this->assertSame(['Fairfax County', 'Henrico County'], $this->places($id));
    }

    public function testGptIsAskedAboutOnlyAFewBillsAtATime(): void
    {
        $ids = [];
        foreach (['Counties of Henrico', 'Counties of Chesterfield', 'Counties of Fairfax'] as $i => $names) {
            $ids[] = $this->bill('The ' . $names . ' and others, ' . $i . '.');
        }
        $stats = $this->tagger()->run(3, 2);

        $this->assertCount(2, $this->asked);
        $this->assertSame(2, $stats['checked']);
        $this->assertSame(1, $stats['waiting']);
        $this->assertSame($ids[0], $this->chosen()[0], 'The one that had to wait (the oldest) is chosen next');
    }

    public function testBillsThatDontNeedGptAreCheckedEvenWhenItsAllocationIsUsedUp(): void
    {
        $this->bill('Fairfax County.');
        $this->bill('Relates to fireworks.');
        $stats = $this->tagger()->run(2, 0);

        $this->assertSame(2, $stats['checked']);
        $this->assertSame([], $this->asked);
    }

    public function testABillWhoseTextCouldNotBeRetrievedIsLeftUnchecked(): void
    {
        $id = $this->bill('Fairfax County.');
        unset($this->texts['hb9001']);   // The API fails
        $stats = $this->tagger()->run(1, 10);

        $this->assertFalse($this->checked($id));
        $this->assertSame([], $this->places($id));
        $this->assertSame(1, $stats['unretrieved']);
        $this->assertSame(0, $stats['checked']);
    }

    public function testABillGptCouldNotBeAskedAboutIsLeftUnchecked(): void
    {
        $text = 'The Counties of Henrico and Chesterfield.';
        $id = $this->bill($text);
        $tagger = new PlaceTagger(self::$db, fn () => $text, fn () => null);
        $stats = $tagger->run(1, 10);

        $this->assertFalse($this->checked($id));
        $this->assertSame(1, $stats['unretrieved']);
    }

    /**
     * The old code reused the previous place's lookup for a name that it couldn't recognize, and
     * so tagged the bill with the wrong place (or the same one over and over).
     */
    public function testAPlaceGptNamesThatCantBeToldApartIsNotTagged(): void
    {
        $text = 'The Counties of Henrico and Chesterfield.';
        $this->answers[$text] = ['County of Henrico', 'Richmond', 'Virginia'];
        $id = $this->bill($text);
        $this->tagger()->run(1, 10);

        $this->assertSame(['Henrico County'], $this->places($id));
    }

    public function testATownThatIsNotInTheGazetteerIsNotTagged(): void
    {
        $text = 'The Town of Nowhereville.';
        $this->answers[$text] = ['Town of Nowhereville'];
        $id = $this->bill($text);
        $this->tagger()->run(1, 10);

        $this->assertSame([], $this->places($id));
        $this->assertTrue($this->checked($id), 'It was checked, and had nothing to tag');
    }

    /**
     * A bill's text changes (cron/full_text.php then clears places_checked), and its old tags are
     * from the old text.
     */
    public function testACheckedAgainBillIsTaggedFromItsCurrentTextOnly(): void
    {
        $id = $this->bill('Imposes a tax in Fairfax County.', ['places' => ['Henrico County', 'Fairfax County']]);
        $this->tagger()->run(1, 10);

        $this->assertSame(['Fairfax County'], $this->places($id));
    }

    public function testCheckingABillLeavesItsModificationDateAlone(): void
    {
        $id = $this->bill('Fairfax County.');
        $this->tagger()->run(1, 10);

        $modified = self::$db->query('SELECT date_modified FROM bills WHERE id = ' . $id)->fetchColumn();
        $this->assertSame('2025-01-02 03:04:05', $modified);
    }

    public function testNothingIsStoredIfTaggingFailsPartway(): void
    {
        $id = $this->bill('Fairfax County.', ['places' => ['Henrico County']]);
        try {
            $this->tagger()->store($id, [
                ['name' => 'Fairfax County', 'latitude' => 38.8, 'longitude' => -77.3],
                ['name' => str_repeat('x', 500), 'latitude' => 1.0, 'longitude' => 1.0],   // Too long for the column
            ]);
            $this->fail('Storing should have failed');
        } catch (\PDOException $e) {
            $this->assertFalse(self::$db->inTransaction());
        }

        $this->assertSame(['Henrico County'], $this->places($id), 'The old tags are still there');
        $this->assertFalse($this->checked($id));
    }

    public function testNoMoreBillsAreStartedAfterTheTimeIsUp(): void
    {
        $this->bill('Fairfax County.');
        $this->bill('Fairfax County.');
        $stats = $this->tagger()->run(2, 10, time() - 1);

        $this->assertSame(0, $stats['checked']);
        $this->assertCount(2, $this->chosen());
    }
}
