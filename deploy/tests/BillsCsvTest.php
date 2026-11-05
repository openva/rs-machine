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
