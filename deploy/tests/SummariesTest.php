<?php

use PHPUnit\Framework\TestCase;
use RsMachine\Summaries;

/**
 * Which of a bill's summaries in Summaries.csv is stored.
 */
class SummariesTest extends TestCase
{
    /**
     * The file is sorted by document ID, which puts the summary as enacted (HB2SCH) before those
     * as passed (HB2SE, HB2SER).
     */
    public function testTheLatestSummaryWinsWhereverItIsInTheFile(): void
    {
        $latest = Summaries::latest([
            $this->row('HB0002', 'HB2S', 'SUMMARY AS INTRODUCED'),
            $this->row('HB0002', 'HB2SCH', "SUMMARY AS ENACTED WITH GOVERNOR'S RECOMMENDATION"),
            $this->row('HB0002', 'HB2SE', 'SUMMARY AS PASSED HOUSE'),
            $this->row('HB0002', 'HB2SER', 'SUMMARY AS PASSED'),
        ]);

        $this->assertSame(['hb2'], array_keys($latest));
        $this->assertSame('HB2SCH', $latest['hb2']['doc_id']);
    }

    /**
     * A bill passes the chamber it started in first.
     */
    public function testTheSecondChambersSummaryIsLater(): void
    {
        $latest = Summaries::latest([
            $this->row('HB0005', 'HB5SE', 'SUMMARY AS PASSED SENATE'),
            $this->row('HB0005', 'HB5SE', 'SUMMARY AS PASSED HOUSE'),
            $this->row('SB0007', 'SB7SE', 'SUMMARY AS PASSED HOUSE'),
            $this->row('SB0007', 'SB7SE', 'SUMMARY AS PASSED SENATE'),
        ]);

        $this->assertSame('SUMMARY AS PASSED SENATE', $latest['hb5']['type']);
        $this->assertSame('SUMMARY AS PASSED HOUSE', $latest['sb7']['type']);
    }

    public function testEachBillGetsItsOwn(): void
    {
        $latest = Summaries::latest([
            $this->row('HB0001', 'HB1S', 'SUMMARY AS INTRODUCED'),
            $this->row('HJ0010', 'HJ10S', 'SUMMARY AS INTRODUCED'),
            $this->row('HB0001', 'HB1SER', 'SUMMARY AS PASSED'),
        ]);

        $this->assertSame(['hb1' => 'HB1SER', 'hj10' => 'HJ10S'], array_map(fn($row) => $row['doc_id'], $latest));
    }

    /**
     * LIS's quoting sometimes breaks a row, leaving a fragment of the summary where its type
     * should be.
     */
    public function testARowWithoutASummaryTypeIsIgnored(): void
    {
        $latest = Summaries::latest([
            $this->row('HB0003', 'HB3S', 'SUMMARY AS INTRODUCED'),
            $this->row('HB0003', ' 2025', ' beginning on July 1'),
        ]);

        $this->assertSame('HB3S', $latest['hb3']['doc_id']);
    }

    public function testAnUnfamiliarTypeComesAfterIntroduction(): void
    {
        $latest = Summaries::latest([
            $this->row('HB0004', 'HB4SV', 'SUMMARY AS VETOED'),
            $this->row('HB0004', 'HB4S', 'SUMMARY AS INTRODUCED'),
        ]);

        $this->assertSame('HB4SV', $latest['hb4']['doc_id']);
    }

    public function testBillNumbersLoseTheirPadding(): void
    {
        $this->assertSame('hb1', Summaries::billNumber('HB0001'));
        $this->assertSame('sjr120', Summaries::billNumber('SJR0120'));
        $this->assertNull(Summaries::billNumber(''));
    }

    /** @return array{number:string,doc_id:string,type:string,text:string} */
    private function row(string $number, string $doc_id, string $type): array
    {
        return ['number' => $number, 'doc_id' => $doc_id, 'type' => $type, 'text' => '<p>' . $doc_id . '</p>'];
    }
}
