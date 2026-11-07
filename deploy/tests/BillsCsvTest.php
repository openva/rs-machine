<?php

use PHPUnit\Framework\TestCase;
use RsMachine\BillsCsv;

/**
 * Fields of BILLS.CSV that bills.php reads itself.
 */
class BillsCsvTest extends TestCase
{
    public function testReadsTheDateSince2025(): void
    {
        $this->assertSame('2025-11-17', BillsCsv::introductionDate($this->row('11/17/2025')));
        $this->assertSame('2026-01-07', BillsCsv::introductionDate($this->row('1/7/2026')));
    }

    public function testReadsTheDateOf2024(): void
    {
        $this->assertSame('2023-11-20', BillsCsv::introductionDate($this->row('2023-11-20T00:00:00')));
    }

    public function testReadsATwoDigitYear(): void
    {
        $this->assertSame('2019-11-18', BillsCsv::introductionDate($this->row('11/18/19')));
    }

    /**
     * Two rows of 2024's file have their columns shifted, putting a chapter number here.
     */
    public function testSomethingElseIsNoDate(): void
    {
        $this->assertNull(BillsCsv::introductionDate($this->row('CHAP0350  ')));
        $this->assertNull(BillsCsv::introductionDate($this->row('')));
        $this->assertNull(BillsCsv::introductionDate($this->row('13/45/2025')));
        $this->assertNull(BillsCsv::introductionDate(['HB1', 'A short row']));
    }

    /**
     * The fixture, a real row of BILLS.CSV.
     */
    public function testReadsTheFixture(): void
    {
        $line = '"HB1","Absentee voting; no excuse required.","H208","Herring","H22","Committee    Referral Pending",'
            . '"11/18/19","","","","","","","","N","N","N","N","N","N","N","N","HB1","11/18/19","","","","","","","",'
            . '"","","","H2201","","","","","11/18/19","H2201"';

        $this->assertSame('2019-11-18', BillsCsv::introductionDate(str_getcsv($line, ',', '"')));
        $this->assertSame(['HB1' => '2019-11-18'], BillsCsv::textVersions(str_getcsv($line, ',', '"')));
    }

    public function testReadsTheTextVersions(): void
    {
        $row = $this->versions('HB397', ['HB397     ', '1/12/2026', 'HB397H1', '1/28/2026', 'HB397ER', '3/5/2026',
            'CHAP0920', '4/13/2026', 'HB397ER2', '4/22/2026']);

        $this->assertSame([
            'HB397' => '2026-01-12',
            'HB397H1' => '2026-01-28',
            'HB397ER' => '2026-03-05',
            'CHAP0920' => '2026-04-13',
            'HB397ER2' => '2026-04-22',
        ], BillsCsv::textVersions($row));
    }

    /**
     * HB30 of 2026 lists its first version twice.
     */
    public function testAVersionListedTwiceIsReadOnce(): void
    {
        $row = $this->versions('HB30', ['HB30', '12/17/2025', 'HB30', '12/17/2025', 'HB30ER', '6/25/2026']);

        $this->assertSame(['HB30' => '2025-12-17', 'HB30ER' => '2026-06-25'], BillsCsv::textVersions($row));
    }

    /**
     * A damaged row once gave HB397 a version "H", with an unreadable date.
     */
    public function testSkipsVersionsThatArentTheBillsOrHaveNoDate(): void
    {
        $row = $this->versions('HB397', ['H', '1/28/2026', 'HB397H1', '1/28', 'HB3970', '1/28/2026',
            'SB397', '1/28/2026', 'HB397', '', 'CHAP', '4/13/2026']);

        $this->assertSame([], BillsCsv::textVersions($row));
        $this->assertSame([], BillsCsv::textVersions(['HB1', 'A short row']));
    }

    /**
     * @param string[] $versions Each version and its date, in turn.
     * @return string[]
     */
    private function versions(string $bill, array $versions): array
    {
        $row = $this->row('');
        $row[0] = $bill;
        array_splice($row, 22, count($versions), $versions);

        return $row;
    }

    /** @return string[] */
    private function row(string $date): array
    {
        $row = array_fill(0, 41, '');
        $row[0] = 'HB1';
        $row[BillsCsv::INTRODUCTION_DATE] = $date;

        return $row;
    }
}
