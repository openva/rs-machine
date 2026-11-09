<?php

use PHPUnit\Framework\TestCase;
use RsMachine\BillCache;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * Clearing the website's cached bill records ("bill-<id>"), and telling which bills a bulk change
 * (cleanup.php's) changed.
 */
class BillCacheTest extends TestCase
{
    /** A session that no real bill is in. */
    private const SESSION_ID = 248;

    private ?PDO $dbh = null;

    protected function tearDown(): void
    {
        if ($this->dbh !== null) {
            $this->cleanUp();
        }
    }

    public function testForgetDeletesEachBillsRecordOnce(): void
    {
        $mc = $this->memcached();

        $this->assertSame(2, BillCache::forget($mc, [12, '34', 12, 0, -1]));
        $this->assertSame([['bill-12', 'bill-34']], $mc->deleted);
    }

    public function testForgetWithNothingToDoDeletesNothing(): void
    {
        $mc = $this->memcached();

        $this->assertSame(0, BillCache::forget($mc, []));
        $this->assertSame(0, BillCache::forget(null, [12]));
        $this->assertSame([], $mc->deleted);
    }

    public function testChangedFindsChangedAndAddedBills(): void
    {
        $this->assertSame(
            [2, 4],
            BillCache::changed([1 => 'a', 2 => 'b', 3 => 'c'], [1 => 'a', 2 => 'x', 4 => 'd'])
        );
    }

    /**
     * What the website caches about a bill changes its fingerprint, including its tags; what it
     * doesn't, like the hotness that cleanup.php recalculates on every run, doesn't.
     */
    public function testFingerprintsChangeWithWhatIsCached(): void
    {
        $this->connect();
        $this->dbh->exec('INSERT INTO bills (number, session_id, chamber, chief_patron_id, status, date_created)
            VALUES ("hb1", ' . self::SESSION_ID . ', "house", 1, "introduced", NOW()),
                   ("hb2", ' . self::SESSION_ID . ', "house", 1, "introduced", NOW()),
                   ("hb3", ' . self::SESSION_ID . ', "house", 1, "introduced", NOW())');
        $ids = $this->dbh->query('SELECT number, id FROM bills WHERE session_id = ' . self::SESSION_ID)
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $before = BillCache::fingerprints($this->dbh, self::SESSION_ID);
        $this->assertCount(3, $before);

        $this->dbh->exec('UPDATE bills SET status = "in committee" WHERE id = ' . $ids['hb1']);
        $this->dbh->exec('INSERT INTO tags (bill_id, tag, date_created) VALUES (' . $ids['hb2'] . ', "fees", NOW())');
        $this->dbh->exec('UPDATE bills SET hotness = 99, interestingness = 99 WHERE id = ' . $ids['hb3']);

        $changed = BillCache::changed($before, BillCache::fingerprints($this->dbh, self::SESSION_ID));
        sort($changed);
        $this->assertSame([(int) $ids['hb1'], (int) $ids['hb2']], $changed);
    }

    /**
     * A Memcached that records what it's asked to delete, rather than deleting it.
     */
    private function memcached(): Memcached
    {
        if (!class_exists('Memcached')) {
            $this->markTestSkipped('The Memcached extension is not installed');
        }

        return new class extends Memcached {
            public array $deleted = [];

            public function deleteMulti(array $keys, int $time = 0): array
            {
                $this->deleted[] = $keys;
                return array_fill_keys($keys, Memcached::RES_NOTFOUND);
            }
        };
    }

    private function connect(): void
    {
        $dbh = @(new Database())->connect();
        if (!($dbh instanceof PDO)) {
            $this->markTestSkipped('Database not available');
        }
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $dbh;
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->dbh->exec('DELETE FROM tags WHERE bill_id IN
            (SELECT id FROM bills WHERE session_id = ' . self::SESSION_ID . ')');
        $this->dbh->exec('DELETE FROM bills WHERE session_id = ' . self::SESSION_ID);
    }
}
