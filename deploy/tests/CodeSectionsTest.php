<?php

use PHPUnit\Framework\TestCase;
use RsMachine\CodeSections;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Database.php';

/**
 * Which sections of the Code of Virginia a bill affects, and how they're stored.
 */
class CodeSectionsTest extends TestCase
{
    /** A session ID that no real session has. */
    private const SESSION_ID = 250;

    /**
     * The format of bill text from the LIS API, since 2026.
     */
    public function testReadsTheEnactmentClausesOfTheApisText(): void
    {
        $html = '<p class="enactstm">Be it enacted by the General Assembly of Virginia:</p>'
            . '<p class="enactcls">1. That §§ <a href=\'https://law.lis.virginia.gov/vacode/9.1-102/\'>9.1-102</a>'
            . ' and <a href=\'https://law.lis.virginia.gov/vacode/18.2-422/\'>18.2-422</a> of the Code of Virginia'
            . ' are amended and reenacted and that the Code of Virginia is amended by adding a section numbered'
            . ' <a href=\'https://law.lis.virginia.gov/vacode/18.2-422.1/\'>18.2-422.1</a> as follows:</p>'
            . '<p class=section>§ 9.1-102. Powers and duties of the Board. As provided in § 2.2-4024 and'
            . ' § 19.2-389, the Board shall…</p>'
            . '<p class=enactcls>2. That §&nbsp;<a href=\'https://law.lis.virginia.gov/vacode/22.1-207.3/\'>22.1-207.3</a>'
            . ' of the Code of Virginia is repealed.</p>';

        $this->assertSame(['9.1-102', '18.2-422', '18.2-422.1', '22.1-207.3'], CodeSections::fromText($html));
    }

    /**
     * The format before 2026, with whitespace between paragraphs.
     */
    public function testReadsTheEnactmentClausesOfOlderText(): void
    {
        $html = 'enacted by the General Assembly of Virginia:</p>     <p>1. That § <a href="http://law.lis.'
            . 'virginia.gov/vacode/54.1-3408">54.1-3408</a> of the Code of Virginia is amended and   reenacted'
            . ' and that the Code of Virginia is amended by adding in Chapter 28 of Title 2.2 a section numbered'
            . ' <a href="http://law.lis.virginia.gov/vacode/2.2-2833">2.2-2833</a> as   follows:</p>     <p><ins>'
            . '§ <a href="http://law.lis.virginia.gov/vacode/2.2-2833">2.2-2833</a>.   Possession of naloxone.'
            . ' Pursuant to § 32.1-11.1, …</ins></p>';

        $this->assertSame(['54.1-3408', '2.2-2833'], CodeSections::fromText($html));
    }

    public function testExpandsARange(): void
    {
        $html = '<p>1. That the Code of Virginia is amended by adding in Title 59.1 a chapter numbered 11.2,'
            . ' consisting of sections numbered 59.1-148.5 through 59.1-148.8, as follows:</p>'
            . '<p>2. That Chapter 5 (§§ 2.2-500 through 2.2-502) of Title 2.2 of the Code of Virginia is'
            . ' repealed.</p>';

        $this->assertSame(
            ['59.1-148.5', '59.1-148.6', '59.1-148.7', '59.1-148.8', '2.2-500', '2.2-501', '2.2-502'],
            CodeSections::fromText($html)
        );
    }

    /**
     * Sections that differ in more than their last number can't be counted between.
     */
    public function testARangeOfDifferentFormsIsJustItsEnds(): void
    {
        $html = '<p>1. That §§ 2.2-100 through 2.2-100.3 of the Code of Virginia are amended and reenacted'
            . ' as follows:</p>';

        $this->assertSame(['2.2-100', '2.2-100.3'], CodeSections::fromText($html));
    }

    public function testReadsUnusualSectionNumbers(): void
    {
        $html = '<p>1. That §§ 8.9A-101, 22.1-253.13:1, and 16.1-69.40:1 of the Code of Virginia, as it is'
            . ' currently effective and as it shall become effective, are amended and reenacted as follows:</p>';

        $this->assertSame(['8.9A-101', '22.1-253.13:1', '16.1-69.40:1'], CodeSections::fromText($html));
    }

    /**
     * An uncodified act ("§ 1.") or a study changes no sections, though it may cite some.
     */
    public function testAnUncodifiedActAffectsNoSections(): void
    {
        $html = '<p>Be it enacted by the General Assembly of Virginia:</p><p class="textbl"><strong>1. </strong>'
            . '§ 1. That the Board of Education shall convene a work group, as described in § 22.1-254 of the'
            . ' Code of Virginia, which may be amended.</p>'
            . '<p>2. That the provisions of this act shall expire on July 1, 2027.</p>';

        $this->assertSame([], CodeSections::fromText($html));
    }

    /**
     * The latest version's sections are stored, and replaced when a newer version changes them.
     */
    public function testStoresTheLatestVersionsSections(): void
    {
        $dbh = $this->database();
        try {
            $bill = $this->insertBill($dbh);
            $this->insertText($dbh, $bill, 'HB1', '2099-01-10', '<p>1. That § 1-200 of the Code of Virginia is amended as follows:</p>');
            $substitute = $this->insertText($dbh, $bill, 'HB1H1', '2099-01-20',
                '<p>1. That §§ 1-200 and 1-201 of the Code of Virginia are amended as follows:</p>');

            $parsed = CodeSections::update($dbh, self::SESSION_ID, [], 10);

            $this->assertSame([$bill => $substitute], $parsed);
            $this->assertSame([[$substitute, '1-200'], [$substitute, '1-201']], $this->stored($dbh, $bill));

            // Parsed already, so not again.
            $dbh->exec('DELETE FROM bills_section_numbers WHERE bill_id = ' . $bill);
            CodeSections::update($dbh, self::SESSION_ID, $parsed, 10);
            $this->assertSame([], $this->stored($dbh, $bill));

            // A newer version is parsed.
            $enrolled = $this->insertText($dbh, $bill, 'HB1ER', '2099-02-20',
                '<p>1. That § 1-201 of the Code of Virginia is amended as follows:</p>');
            $parsed = CodeSections::update($dbh, self::SESSION_ID, $parsed, 10);
            $this->assertSame([$bill => $enrolled], $parsed);
            $this->assertSame([[$enrolled, '1-201']], $this->stored($dbh, $bill));
        } finally {
            $this->cleanUp($dbh);
        }
    }

    public function testParsesNoMoreThanTheLimit(): void
    {
        $dbh = $this->database();
        try {
            foreach (['HB1', 'HB2', 'HB3'] as $number) {
                $bill = $this->insertBill($dbh, strtolower($number));
                $this->insertText($dbh, $bill, $number, '2099-01-10', '<p>1. That § 1-200 of the Code of Virginia is amended.</p>');
            }

            $parsed = CodeSections::update($dbh, self::SESSION_ID, [], 2);
            $this->assertCount(2, $parsed);

            $this->assertCount(3, CodeSections::update($dbh, self::SESSION_ID, $parsed, 2));
        } finally {
            $this->cleanUp($dbh);
        }
    }

    public function testStopsAtTheDeadline(): void
    {
        $dbh = $this->database();
        try {
            $bill = $this->insertBill($dbh);
            $this->insertText($dbh, $bill, 'HB1', '2099-01-10', '<p>1. That § 1-200 of the Code of Virginia is amended.</p>');

            $this->assertSame([], CodeSections::update($dbh, self::SESSION_ID, [], 10, microtime(true) - 1));
            $this->assertCount(1, CodeSections::update($dbh, self::SESSION_ID, [], 10, microtime(true) + 60));
        } finally {
            $this->cleanUp($dbh);
        }
    }

    private function database(): PDO
    {
        $dbh = @(new Database())->connect();
        if (!($dbh instanceof PDO)) {
            $this->markTestSkipped('Database not available');
        }
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->cleanUp($dbh);

        return $dbh;
    }

    private function insertBill(PDO $dbh, string $number = 'hb1'): int
    {
        $dbh->prepare('INSERT INTO bills (number, session_id, chamber, chief_patron_id, date_created)
            VALUES (:number, :session_id, "house", 1, NOW())')
            ->execute([':number' => $number, ':session_id' => self::SESSION_ID]);

        return (int) $dbh->lastInsertId();
    }

    private function insertText(PDO $dbh, int $bill, string $number, string $date, string $text): int
    {
        $dbh->prepare('INSERT INTO bills_full_text (bill_id, number, session_id, date_introduced, text, date_created)
            VALUES (:bill_id, :number, :session_id, :date, :text, NOW())')
            ->execute([':bill_id' => $bill, ':number' => $number, ':session_id' => self::SESSION_ID,
                ':date' => $date, ':text' => $text]);

        return (int) $dbh->lastInsertId();
    }

    /** @return array<int,array{0:int,1:string}> */
    private function stored(PDO $dbh, int $bill): array
    {
        $rows = $dbh->query('SELECT full_text_id, section_number FROM bills_section_numbers
            WHERE bill_id = ' . $bill . ' ORDER BY section_number')->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn(array $row): array => [(int) $row[0], $row[1]], $rows);
    }

    private function cleanUp(PDO $dbh): void
    {
        $dbh->exec('DELETE n FROM bills_section_numbers n JOIN bills b ON b.id = n.bill_id
            WHERE b.session_id = ' . self::SESSION_ID);
        $dbh->exec('DELETE FROM bills_full_text WHERE session_id = ' . self::SESSION_ID);
        $dbh->exec('DELETE FROM bills WHERE session_id = ' . self::SESSION_ID);
    }
}
