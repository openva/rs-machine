<?php

use PHPUnit\Framework\TestCase;
use RsMachine\Sessions;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * How LIS's sessions become rows of sessions.
 *
 * deploy/tests/data/lis_api/session_sample.json is sessions from GetSessionListAsync, as LIS
 * returned them in November 2026.
 */
class SessionsTest extends TestCase
{
    /** @var array<string,array<string,mixed>> The sample's sessions, by code. */
    private array $sample;

    private ?PDO $dbh = null;

    protected function setUp(): void
    {
        $sample = json_decode(
            file_get_contents(__DIR__ . '/data/lis_api/session_sample.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        foreach ($sample['Sessions'] as $session) {
            $this->sample[$session['SessionCode']] = $session;
        }
    }

    protected function tearDown(): void
    {
        if ($this->dbh !== null) {
            $this->dbh->exec("DELETE FROM sessions WHERE lis_id IN ('991', '992', '993')");
        }
    }

    public function testARegularSession(): void
    {
        $this->assertSame([
            'lis_id'       => '261',
            'lis_api_id'   => 59,
            'year'         => 2026,
            'suffix'       => null,
            'date_started' => '2026-01-14',
            // Labeled with Closing's event type.
            'date_ended'   => '2026-03-14',
            'crossover'    => null,
            'active'       => true,
        ], Sessions::fromLis($this->sample['20261']));
    }

    public function testASpecialSession(): void
    {
        $special = Sessions::fromLis($this->sample['20213']);
        $this->assertSame('213', $special['lis_id']);
        $this->assertSame('2', $special['suffix']);
        $this->assertSame('2021-08-02', $special['date_started']);
        $this->assertSame('2021-11-01', $special['date_ended']);
    }

    public function testASessionNotYetAdjourned(): void
    {
        $session = Sessions::fromLis($this->sample['20271']);
        $this->assertSame('2027-01-13', $session['date_started']);
        $this->assertNull($session['date_ended']);
    }

    public function testCrossoverIsTakenWhenLisHasIt(): void
    {
        $this->assertSame('2024-02-13', Sessions::fromLis($this->sample['20241'])['crossover']);
    }

    public function testASessionWithoutAStartIsNotOne(): void
    {
        $session = $this->sample['20271'];
        $session['SessionEvents'] = array_values(array_filter(
            $session['SessionEvents'],
            static fn($e) => $e['DisplayName'] !== 'Session Start'
        ));
        $this->assertNull(Sessions::fromLis($session));
    }

    public function testACodeThatDisagreesWithItsTypeIsNotASession(): void
    {
        $session = $this->sample['20262'];
        $session['SessionType'] = 'Regular';
        $this->assertNull(Sessions::fromLis($session));
    }

    public function testAMissingSessionIsAdded(): void
    {
        $lis = [Sessions::fromLis($this->sample['20262']), Sessions::fromLis($this->sample['20261'])];
        $changes = Sessions::changes($lis, [
            ['id' => 32, 'lis_id' => '261', 'lis_api_id' => 59, 'date_started' => '2026-01-14', 'date_ended' => '2026-03-14', 'crossover' => '2026-02-19'],
        ]);

        $this->assertSame([], $changes['update']);
        $this->assertCount(1, $changes['insert']);
        $this->assertSame('262', $changes['insert'][0]['lis_id']);
        $this->assertArrayNotHasKey('active', $changes['insert'][0]);
    }

    public function testAnOlderSessionIsNotAdded(): void
    {
        // We have 2026's regular session, and not 2024's special session.
        $lis = [Sessions::fromLis($this->sample['20242']), Sessions::fromLis($this->sample['20261'])];
        $changes = Sessions::changes($lis, [
            ['id' => 32, 'lis_id' => '261', 'lis_api_id' => 59, 'date_started' => '2026-01-14', 'date_ended' => '2026-03-14', 'crossover' => null],
        ]);
        $this->assertSame(['insert' => [], 'update' => []], $changes);
    }

    public function testNewSessionsAreAddedOldestFirst(): void
    {
        $lis = [Sessions::fromLis($this->sample['20271']), Sessions::fromLis($this->sample['20262'])];
        $changes = Sessions::changes($lis, []);
        $this->assertSame(['262', '271'], array_column($changes['insert'], 'lis_id'));
    }

    public function testWhatWeLackIsFilledIn(): void
    {
        $changes = Sessions::changes([Sessions::fromLis($this->sample['20241'])], [
            ['id' => 30, 'lis_id' => '241', 'lis_api_id' => null, 'date_started' => '2024-01-10', 'date_ended' => '0000-00-00', 'crossover' => null],
        ]);
        $this->assertSame(
            [30 => ['lis_api_id' => 55, 'date_ended' => '2024-03-09', 'crossover' => '2024-02-13']],
            $changes['update']
        );
    }

    public function testWhatWeHaveIsKept(): void
    {
        // As we have 2024 recorded, but with a different adjournment; it's no longer active.
        $changes = Sessions::changes([Sessions::fromLis($this->sample['20241'])], [
            ['id' => 30, 'lis_id' => '241', 'lis_api_id' => 55, 'date_started' => '2024-01-10', 'date_ended' => '2024-04-17', 'crossover' => '2024-02-14'],
        ]);
        $this->assertSame([], $changes['update']);
    }

    public function testAnActiveSessionsProjectedAdjournmentIsCorrected(): void
    {
        $changes = Sessions::changes([Sessions::fromLis($this->sample['20261'])], [
            ['id' => 32, 'lis_id' => '261', 'lis_api_id' => 59, 'date_started' => '2026-01-14', 'date_ended' => '2026-03-07', 'crossover' => null],
        ]);
        $this->assertSame([32 => ['date_ended' => '2026-03-14']], $changes['update']);
    }

    public function testAProjectedAdjournmentIsKeptUntilLisHasOne(): void
    {
        $changes = Sessions::changes([Sessions::fromLis($this->sample['20271'])], [
            ['id' => 34, 'lis_id' => '271', 'lis_api_id' => 61, 'date_started' => '2027-01-13', 'date_ended' => '2027-02-27', 'crossover' => null],
        ]);
        $this->assertSame([], $changes['update']);
    }

    public function testNames(): void
    {
        $this->assertSame('2027 Regular Session', Sessions::name(['year' => 2027, 'suffix' => null]));
        $this->assertSame('2026 Special Session I', Sessions::name(['year' => 2026, 'suffix' => '1']));
    }

    public function testApplyAddsAndUpdatesSessions(): void
    {
        $dbh = $this->database();
        $dbh->exec("INSERT INTO sessions (lis_id, year, date_started, notes, date_created)
            VALUES ('991', 2099, '2099-01-14', '', NOW())");
        $existing = (int) $dbh->lastInsertId();
        $lis = [
            $this->fake('20991', 'Regular', 251, '2099-01-14', '2099-03-14'),
            $this->fake('20992', 'Special', 252, '2099-04-22', null),
            $this->fake('20993', 'Special', 253, '2099-09-01', '2099-09-05'),
        ];

        $changes = Sessions::apply($dbh, array_merge($lis, [['SessionCode' => 'nonsense']]));

        $this->assertSame(['992', '993'], array_column($changes['insert'], 'lis_id'));
        $this->assertSame([$existing => ['lis_api_id' => 251, 'date_ended' => '2099-03-14']], $changes['update']);

        $published = array_column(Sessions::published($dbh), null, 'lis_id');
        $this->assertSame([
            'id'           => $changes['insert'][0]['id'],
            'lis_id'       => '992',
            'lis_api_id'   => 252,
            'year'         => 2099,
            'suffix'       => '1',
            'date_started' => '2099-04-22',
            'date_ended'   => null,
            'crossover'    => null,
        ], $published['992']);
        $this->assertSame('2099-03-14', $published['991']['date_ended']);
        $this->assertSame('2', $published['993']['suffix']);

        // Once they agree, nothing changes.
        $this->assertSame(['insert' => [], 'update' => []], Sessions::apply($dbh, $lis));
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

        // Other tests add sessions with high IDs (up to 255, the most sessions.id can hold), and
        // the counter doesn't go back when they're removed. This sets it after the highest left.
        $dbh->exec('ALTER TABLE sessions AUTO_INCREMENT = 1');

        return $dbh;
    }

    private function fake(string $code, string $type, int $id, string $start, ?string $end): array
    {
        $events = [['DisplayName' => 'Session Start', 'ActualDate' => $start . 'T00:00:00', 'EventTypeID' => 1]];
        if ($end !== null) {
            $events[] = ['DisplayName' => 'Adjournment', 'ActualDate' => $end . 'T00:00:00', 'EventTypeID' => 8];
        }

        return [
            'SessionID'     => $id,
            'SessionCode'   => $code,
            'SessionYear'   => (int) substr($code, 0, 4),
            'SessionType'   => $type,
            'IsActive'      => false,
            'SessionEvents' => $events,
        ];
    }
}
