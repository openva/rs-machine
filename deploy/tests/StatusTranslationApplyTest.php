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

    /**
     * SB345 (2026), in the House: the committee reported the bill, then continued it, and the
     * continuation is what happened.
     */
    public function testAFailureAfterAReportIsAFailure(): void
    {
        $this->entry('2026-03-02 14:30:00', 'Reported from Courts of Justice with substitute (Voice Vote)');
        $failure = $this->entry('2026-03-02 14:30:00', 'Continued to 2027 in Courts of Justice (Voice Vote)');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('failed committee', $this->translation($failure));
        $this->assertSame('failed committee', $this->billStatus());
    }

    /**
     * SB228 (2026): a failed motion, then the report, both at midnight.
     */
    public function testAReportAfterAFailureIsAReport(): void
    {
        $failure = $this->entry('2026-02-04 00:00:00', 'Failed to report from Courts of Justice with substitute');
        $this->entry('2026-02-04 00:00:00', 'Reported from Courts of Justice with substitute and rereferred to Finance and Appropriations');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertNull($this->translation($failure));
        $this->assertSame('passed committee', $this->billStatus());
    }

    /**
     * HB244 (2026): LIS dates the committee's action at midnight, and the referral before it at
     * 3:31 p.m.
     */
    public function testAReferralDoesNotOutrankTheCommitteesFailure(): void
    {
        $this->entry('2026-03-09 00:00:00', 'Continued to 2027 in Finance and Appropriations (10-Y 4-N)');
        $this->entry('2026-03-09 15:31:57', 'Referred to Committee on Finance and Appropriations');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('failed committee', $this->billStatus());
    }

    /**
     * HB919 (2026), rereferred on the floor, then continued under House Rule 22.
     */
    public function testARereferralDoesNotOutrankTheCommitteesFailure(): void
    {
        $this->entry('2026-02-16 05:00:00', 'Continued pursuant to House Rule 22 to 2027 in Finance');
        $this->entry('2026-02-16 14:00:02', 'Rereferred to Finance');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('failed committee', $this->billStatus());
    }

    /**
     * A chamber can rerefer a bill to the committee that just reported it, and a failure in one
     * committee says nothing of a referral to another.
     */
    public function testAReferralAfterAnythingElseCounts(): void
    {
        $this->entry('2026-02-16 00:00:00', 'Reported from Finance with substitute (14-Y 7-N)');
        $this->entry('2026-02-16 14:00:02', 'Rereferred to Finance');
        StatusTranslation::apply($this->dbh, self::SESSION_ID);
        $this->assertSame('in committee', $this->billStatus());

        $this->entry('2026-02-17 00:00:00', 'Left in Finance');
        $this->entry('2026-02-17 14:00:00', 'Referred to Committee on Appropriations');
        StatusTranslation::apply($this->dbh, self::SESSION_ID);
        $this->assertSame('in committee', $this->billStatus());
    }

    /**
     * SB342 (2026), whose only record of becoming law is its chapter.
     */
    public function testAChapterEnactsABill(): void
    {
        $this->entry('2026-03-12 00:00:00', 'Signed by President');
        $this->entry('2026-04-22 00:00:00', 'Assigned Chapter 980 (effective 7/1/2026)');
        $this->entry('2026-04-22 17:33:06', 'Passed in enrolled form (73-Y 25-N 0-A)');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('enacted', $this->billStatus());
    }

    public function testAChapterDoesNotOutrankTheGovernorsSignature(): void
    {
        $this->entry('2026-03-12 00:00:00', 'Signed by President');
        $this->entry('2026-04-13 00:00:00', 'Approved by Governor-Chapter 974 (effective 7/1/2026)');
        $this->entry('2026-04-13 04:00:00', 'Assigned Chapter 974 (Effective  7/1/2026)');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('signed by governor', $this->billStatus());
    }

    /**
     * SB643 (2026).
     */
    public function testDifferentConferenceReportsFailABill(): void
    {
        $this->entry('2026-03-04 00:00:00', 'Passed House with substitute (61-Y 37-N 0-A)');
        $this->entry('2026-03-14 00:00:00', 'Different conference report agreed to by each chamber (failed)');
        $this->entry('2026-03-14 12:00:00', 'Conference report agreed to by Senate (21-Y 18-N 0-A)');

        StatusTranslation::apply($this->dbh, self::SESSION_ID);

        $this->assertSame('failed', $this->billStatus());
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
