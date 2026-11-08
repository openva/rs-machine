<?php

use PHPUnit\Framework\TestCase;
use RsMachine\StatusTranslation;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * StatusTranslation::apply(), which translates a session's history entries and sets its bills'
 * statuses, here with SB382 (2026)'s history, abridged.
 */
class StatusTranslationApplyTest extends TestCase
{
    private const SESSION_ID = 249;

    private PDO $dbh;
    private int $bill;

    protected function setUp(): void
    {
        $dbh = @(new Database())->connect();
        if (!($dbh instanceof PDO)) {
            $this->markTestSkipped('Database not available');
        }
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $dbh;
        $this->cleanUp();

        $dbh->exec('INSERT INTO bills (number, session_id, chamber, chief_patron_id, date_created)
            VALUES ("sb382", ' . self::SESSION_ID . ', "senate", 1, NOW())');
        $this->bill = (int) $dbh->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->dbh)) {
            $this->cleanUp();
        }
    }

    public function testAFailedMotionIsNotTranslated(): void
    {
        $this->entry('2026-02-09 00:00:00', 'Reported from Commerce and Labor with substitute and rereferred to Finance and Appropriations (9-Y 5-N)');
        $failure = $this->entry('2026-02-11 00:00:00', 'Continued to 2027 in Finance and Appropriations');
        $this->entry('2026-02-11 00:00:00', 'Reported from Finance and Appropriations with substitute (11-Y 4-N)');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertNull($this->translation($failure));
        $this->assertSame('passed committee', $this->billStatus());
    }

    /**
     * The failure was translated in an earlier run, before the committee's report arrived.
     */
    public function testAFailedMotionIsUntranslatedOnceTheReportArrives(): void
    {
        $failure = $this->entry('2026-02-11 00:00:00', 'Continued to 2027 in Finance and Appropriations');
        StatusTranslation::apply($this->dbh, self::SESSION_ID);
        $this->assertSame('failed committee', $this->billStatus());

        $this->entry('2026-02-11 00:00:00', 'Reported from Finance and Appropriations with substitute (11-Y 4-N)');
        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertNull($this->translation($failure));
        $this->assertSame('passed committee', $this->billStatus());

        // And it stays that way.
        $this->assertSame(0, StatusTranslation::apply($this->dbh, self::SESSION_ID));
    }

    public function testAFailureIsStillAFailure(): void
    {
        $this->entry('2026-02-09 00:00:00', 'Reported from Commerce and Labor with substitute and rereferred to Finance and Appropriations (9-Y 5-N)');
        $failure = $this->entry('2026-02-11 00:00:00', 'Continued to 2027 in Finance and Appropriations');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('failed committee', $this->translation($failure));
        $this->assertSame('failed committee', $this->billStatus());
    }

    /**
     * LIS gives a continued bill's new-session record its whole history, including the previous
     * session's (HB9 (2027), abridged).
     */
    public function testAContinuedBillIsInCommittee(): void
    {
        $this->entry('2025-12-19 00:00:00', 'Referred to Committee on Rules');
        $this->entry('2026-02-06 00:00:00', 'Continued to 2027 in Rules (Voice Vote)');
        $this->entry('2026-07-21 00:00:00', 'Continued from last session');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('in committee', $this->billStatus());
    }

    private function entry(string $date, string $status): int
    {
        $this->dbh->prepare('INSERT INTO bills_status (bill_id, session_id, status, date, date_created)
            VALUES (:bill_id, :session_id, :status, :date, NOW())')
            ->execute([':bill_id' => $this->bill, ':session_id' => self::SESSION_ID, ':status' => $status, ':date' => $date]);

        return (int) $this->dbh->lastInsertId();
    }

    private function translation(int $id): ?string
    {
        $translation = $this->dbh->query('SELECT translation FROM bills_status WHERE id = ' . $id)->fetchColumn();

        return $translation === null ? null : (string) $translation;
    }

    private function billStatus(): ?string
    {
        $status = $this->dbh->query('SELECT status FROM bills WHERE id = ' . $this->bill)->fetchColumn();

        return $status === null ? null : (string) $status;
    }

    private function cleanUp(): void
    {
        $this->dbh->exec('DELETE FROM bills_status WHERE session_id = ' . self::SESSION_ID);
        $this->dbh->exec('DELETE FROM bills WHERE session_id = ' . self::SESSION_ID);
    }
}
