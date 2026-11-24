<?php

use PHPUnit\Framework\TestCase;
use RsMachine\StatusHistoryDuplicates;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * Merging history entries stored more than once, here as HB1557 (2025)'s were: from LIS's CSV,
 * at midnight, and again from its API, with times, on November 16, 2026.
 */
class StatusHistoryDuplicatesTest extends TestCase
{
    /** A bill ID that no real bill has: the largest a mediumint holds, but one. */
    private const BILL_ID = 16777214;
    private const SESSION_ID = 249;

    private ?PDO $dbh = null;

    protected function tearDown(): void
    {
        $this->dbh?->exec('DELETE FROM bills_status WHERE bill_id = ' . self::BILL_ID);
    }

    public function testTheFirstRecordedIsKeptWithTheApisTime(): void
    {
        $this->assertSame([
            'keep'   => 126905694,
            'set'    => ['date' => '2024-11-21 14:18:00'],
            'delete' => [501225181],
        ], StatusHistoryDuplicates::merge([
            $this->row(501225181, '2024-11-21 14:18:00', 'in committee', '2026-11-16 23:11:27'),
            $this->row(126905694, '2024-11-21 00:00:00', 'in committee', '2025-01-09 03:02:01'),
        ]));
    }

    public function testWhatTheKeptLacksIsTakenFromTheLatestCopy(): void
    {
        $merge = StatusHistoryDuplicates::merge([
            $this->row(1, '2026-02-10 00:00:00', null, '2026-02-10 06:00:00'),
            $this->row(2, '2026-02-10 05:00:00', 'passed committee', '2026-02-10 07:00:00', 'H1234'),
            $this->row(3, '2026-02-10 14:30:00', 'reported', '2026-02-11 07:00:00'),
        ]);

        $this->assertSame(1, $merge['keep']);
        $this->assertSame(
            ['date' => '2026-02-10 14:30:00', 'translation' => 'reported', 'lis_vote_id' => 'H1234'],
            $merge['set']
        );
        $this->assertSame([2, 3], $merge['delete']);
    }

    public function testATimeIsNotReplacedWithMidnight(): void
    {
        $merge = StatusHistoryDuplicates::merge([
            $this->row(1, '2026-02-10 14:30:00', 'passed committee', '2026-02-10 15:00:00'),
            $this->row(2, '2026-02-10 00:00:00', 'passed committee', '2026-02-11 07:00:00'),
        ]);

        $this->assertSame(['keep' => 1, 'set' => [], 'delete' => [2]], $merge);
    }

    public function testWordingThatDiffersOnlyInSpacesOrBlockVotesIsTheSameEntry(): void
    {
        $this->assertSame(
            StatusHistoryDuplicates::key('Passed Senate (40-Y 0-N)', '2025-01-28 00:00:00'),
            StatusHistoryDuplicates::key('Passed Senate  Block Vote (40-Y 0-N)', '2025-01-28 12:01:00')
        );
        $this->assertSame(
            StatusHistoryDuplicates::key(" Governor's Action Deadline", '2025-04-02 00:00:00'),
            StatusHistoryDuplicates::key("Governor's Action Deadline", '2025-04-02 00:00:00')
        );
        $this->assertNotSame(
            StatusHistoryDuplicates::key('Passed Senate (40-Y 0-N)', '2025-01-28 00:00:00'),
            StatusHistoryDuplicates::key('Passed Senate (40-Y 0-N)', '2025-01-29 00:00:00')
        );
    }

    public function testTheKeptTakesTheLatestWording(): void
    {
        $older = $this->row(1, '2025-01-28 00:00:00', 'passed senate', '2025-01-29 02:00:00');
        $newer = $this->row(2, '2025-01-28 12:01:00', null, '2026-11-16 23:11:27');
        $older['status'] = 'Passed Senate (40-Y 0-N)';
        $newer['status'] = 'Passed Senate  Block Vote (40-Y 0-N)';

        $this->assertSame(
            ['keep' => 1, 'set' => ['status' => 'Passed Senate  Block Vote (40-Y 0-N)', 'date' => '2025-01-28 12:01:00'], 'delete' => [2]],
            StatusHistoryDuplicates::merge([$older, $newer])
        );
    }

    public function testEntriesStoredMoreThanOnceAreMerged(): void
    {
        $dbh = $this->database();
        $this->insert('Referred to Committee on Finance', '2024-11-21 00:00:00', 'in committee', '2025-01-09 03:02:01');
        $this->insert('Left in Finance', '2025-02-04 00:00:00', 'failed committee', '2025-02-05 02:02:03');
        $this->insert('Referred to Committee on Finance', '2024-11-21 14:18:00', null, '2026-11-16 23:11:27');
        $this->insert(' Governor\'s Action Deadline 11:59 p.m., May 2, 2025', '2025-04-02 00:00:00', null, '2025-04-11 02:00:00');
        $this->insert('Governor\'s Action Deadline 11:59 p.m., May 2, 2025', '2025-04-02 00:00:00', null, '2026-11-16 23:11:27');
        // Only once, but the same text as another entry on another day.
        $this->insert('Left in Finance', '2025-02-05 09:00:00', null, '2026-11-16 23:11:27');

        $entries = StatusHistoryDuplicates::find($dbh, self::SESSION_ID);
        $this->assertSame([self::BILL_ID], array_keys($entries));
        $this->assertCount(2, $entries[self::BILL_ID]);

        $this->assertSame(2, StatusHistoryDuplicates::apply($dbh, $entries));

        $this->assertSame([
            ['status' => 'Referred to Committee on Finance', 'date' => '2024-11-21 14:18:00',
                'translation' => 'in committee', 'date_created' => '2025-01-09 03:02:01'],
            ['status' => 'Left in Finance', 'date' => '2025-02-04 00:00:00',
                'translation' => 'failed committee', 'date_created' => '2025-02-05 02:02:03'],
            ['status' => 'Left in Finance', 'date' => '2025-02-05 09:00:00',
                'translation' => null, 'date_created' => '2026-11-16 23:11:27'],
            ['status' => 'Governor\'s Action Deadline 11:59 p.m., May 2, 2025', 'date' => '2025-04-02 00:00:00',
                'translation' => null, 'date_created' => '2025-04-11 02:00:00'],
        ], $dbh->query('SELECT status, date, translation, date_created FROM bills_status
            WHERE bill_id = ' . self::BILL_ID . ' ORDER BY date')->fetchAll(PDO::FETCH_ASSOC));

        $this->assertSame([], StatusHistoryDuplicates::find($dbh, self::SESSION_ID));
    }

    private function row(int $id, string $date, ?string $translation, string $created, ?string $vote = null): array
    {
        return ['id' => $id, 'status' => 'Referred to Committee on Finance', 'date' => $date,
            'translation' => $translation, 'chamber' => null, 'lis_vote_id' => $vote,
            'date_created' => $created];
    }

    private function insert(string $status, string $date, ?string $translation, string $created): void
    {
        $this->dbh->prepare('INSERT INTO bills_status (bill_id, session_id, status, translation, date, date_created)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::BILL_ID, self::SESSION_ID, $status, $translation, $date, $created]);
    }

    private function database(): PDO
    {
        $dbh = @(new Database())->connect();
        if (!($dbh instanceof PDO)) {
            $this->markTestSkipped('Database not available');
        }
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $dbh;
        $this->tearDown();

        return $dbh;
    }
}
