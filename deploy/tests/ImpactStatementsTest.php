<?php

use PHPUnit\Framework\TestCase;
use RsMachine\ImpactStatements;

/**
 * The reference IDs and PDF URLs here are real ones. The latestPerAgency() cases are those of
 * richmondsunlight.com's ImpactStatementTest, for Bill2::latest_impact_statements(), which must
 * follow the same rule.
 */
class ImpactStatementsTest extends TestCase
{
    private static function statement(int $id, string $lis_id, ?string $pdf_url = null, ?string $summary = null): array
    {
        return ['id' => $id, 'lis_id' => $lis_id, 'pdf_url' => $pdf_url, 'summary' => $summary];
    }

    private static function pdf(int $number, string $host = 'https://lis.blob.core.windows.net/files/'): string
    {
        return $host . $number . '.PDF';
    }

    /*
     * Parsing
     */

    public function testParsesTheCsv(): void
    {
        $csv = "\"HST_BILNO\", \"HST_REFID\", \"HST_URL\"\r\n"
            . "\"HB1\",\"HB1F122\",\"https://lis.blob.core.windows.net/files/1091483.PDF\"\r\n"
            . "\"HB2\",\"HB2F122\",\"\"\r\n"
            . "not,enough\r\n"
            . "\r\n";

        $this->assertSame([
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => self::pdf(1091483)],
            ['bill_number' => 'hb2', 'lis_id' => 'HB2F122', 'pdf_url' => null],
        ], ImpactStatements::parseCsv($csv));
    }

    public function testReadsPdfNumbersOnAnyHost(): void
    {
        $this->assertSame(1220077, ImpactStatements::pdfNumber(self::pdf(1220077)));
        $this->assertSame(1043153, ImpactStatements::pdfNumber(
            'https://lisdoc.blob.core.windows.net/filesdev/1043153.PDF'
        ));
        $this->assertSame(0, ImpactStatements::pdfNumber(null));
        $this->assertSame(0, ImpactStatements::pdfNumber('https://example.com/statement.pdf'));
    }

    public function testReadsAgencyCodes(): void
    {
        $this->assertSame('122', ImpactStatements::agencyCode('SB738S1F122'));
        $this->assertSame('160', ImpactStatements::agencyCode('HB217ERF160'));
        $this->assertSame('122', ImpactStatements::agencyCode('FS1122'));
        $this->assertSame('160', ImpactStatements::agencyCode('F160'));
        $this->assertNull(ImpactStatements::agencyCode('something odd'));
    }

    /*
     * Deduplication
     */

    public function testKeepsTheNewestPdfForAReissuedStatement(): void
    {
        // HB1 (2026): reissued, with the older PDF listed last.
        $rows = [
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => self::pdf(1095197)],
            ['bill_number' => 'hb1', 'lis_id' => 'HB1ERF122', 'pdf_url' => self::pdf(1206636)],
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => self::pdf(1091483)],
        ];

        $this->assertSame([
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => self::pdf(1095197)],
            ['bill_number' => 'hb1', 'lis_id' => 'HB1ERF122', 'pdf_url' => self::pdf(1206636)],
        ], ImpactStatements::dedupe($rows));
    }

    public function testKeepsTheSameReferenceIdOnDifferentBillsApart(): void
    {
        $rows = [
            ['bill_number' => 'hb1', 'lis_id' => 'F122', 'pdf_url' => self::pdf(2)],
            ['bill_number' => 'hb2', 'lis_id' => 'F122', 'pdf_url' => self::pdf(1)],
        ];

        $this->assertSame($rows, ImpactStatements::dedupe($rows));
    }

    public function testPrefersAPdfToNone(): void
    {
        $rows = [
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => null],
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => self::pdf(1091483)],
            ['bill_number' => 'hb1', 'lis_id' => 'HB1F122', 'pdf_url' => null],
        ];

        $this->assertSame([$rows[1]], ImpactStatements::dedupe($rows));
    }

    /*
     * The newest statement from each agency
     */

    public function testKeepsTheMostRecentStatementAcrossBillVersions(): void
    {
        $latest = ImpactStatements::latestPerAgency([
            self::statement(1, 'F122'),
            self::statement(2, 'SB738F122', self::pdf(1012851)),
            self::statement(3, 'SB738ERF122', self::pdf(1069737)),
            self::statement(4, 'SB738H1F122', self::pdf(1043153)),
            self::statement(5, 'SB738S1F122', self::pdf(1029961)),
        ]);

        $this->assertSame(['SB738ERF122'], array_column($latest, 'lis_id'));
    }

    public function testKeepsTheMostRecentStatementFromEachAgency(): void
    {
        $latest = ImpactStatements::latestPerAgency([
            self::statement(1, 'HB217F160', self::pdf(1083210)),
            self::statement(2, 'HB217F122', self::pdf(1096308)),
            self::statement(3, 'HB217ERF122', self::pdf(1215058)),
            self::statement(4, 'HB217ERF160', self::pdf(1215570)),
            self::statement(5, 'HB217H1F160', self::pdf(1105654)),
            self::statement(6, 'HB217H1F122', self::pdf(1138719)),
            self::statement(7, 'HB217H2F160', self::pdf(1220077)),
            self::statement(8, 'HB217S2F160', self::pdf(1195986)),
        ]);

        $this->assertSame(['HB217ERF122', 'HB217H2F160'], array_column($latest, 'lis_id'));
    }

    public function testReadsStatementsFromBefore2025ByWhenTheyWereRecorded(): void
    {
        $latest = ImpactStatements::latestPerAgency([
            self::statement(10, 'F122', null, 'Introduced'),
            self::statement(20, 'FS3122', null, 'Substitute'),
            self::statement(30, 'F160', null, 'Sentencing'),
        ]);

        $this->assertSame(['FS3122', 'F160'], array_column($latest, 'lis_id'));
    }

    public function testFindsTheNewestPdfOnAnyHost(): void
    {
        $latest = ImpactStatements::latestPerAgency([
            self::statement(1, 'SB738H1F122', self::pdf(1043153, 'https://lisdoc.blob.core.windows.net/filesdev/')),
            self::statement(2, 'SB738S1F122', self::pdf(1029961)),
        ]);

        $this->assertSame(['SB738H1F122'], array_column($latest, 'lis_id'));
    }

    public function testKeepsStatementsWhoseIdsCantBeRead(): void
    {
        $latest = ImpactStatements::latestPerAgency([
            self::statement(1, 'something odd'),
            self::statement(2, 'another'),
            self::statement(3, 'HB1F122', self::pdf(1091483)),
        ]);

        $this->assertCount(3, $latest);
    }

    public function testReturnsNothingForNoStatements(): void
    {
        $this->assertSame([], ImpactStatements::latestPerAgency([]));
    }

    /*
     * Choosing what to summarize
     */

    private static function candidate(int $bill_id, int $views, int $id, string $lis_id, ?string $pdf_url, ?string $summary = null): array
    {
        return self::statement($id, $lis_id, $pdf_url, $summary) + ['bill_id' => $bill_id, 'views' => $views];
    }

    public function testSummarizesOnlyTheNewestStatementFromEachAgency(): void
    {
        $chosen = ImpactStatements::toSummarize([
            self::candidate(1, 5, 1, 'HB217F122', self::pdf(1096308)),
            self::candidate(1, 5, 2, 'HB217ERF122', self::pdf(1215058)),
            self::candidate(1, 5, 3, 'HB217H1F160', self::pdf(1105654)),
            self::candidate(1, 5, 4, 'HB217H2F160', self::pdf(1220077)),
        ], 10);

        $this->assertSame(['HB217ERF122', 'HB217H2F160'], array_column($chosen, 'lis_id'));
    }

    public function testSkipsASupersededStatementEvenWhenTheNewestIsSummarized(): void
    {
        // The newest statement is already summarized; the older, unsummarized one isn't shown.
        $chosen = ImpactStatements::toSummarize([
            self::candidate(1, 5, 1, 'HB1F122', self::pdf(1091483)),
            self::candidate(1, 5, 2, 'HB1ERF122', self::pdf(1206636), 'Summarized.'),
        ], 10);

        $this->assertSame([], $chosen);
    }

    public function testSkipsTheNewestStatementWhenItHasNoPdf(): void
    {
        $chosen = ImpactStatements::toSummarize([
            self::candidate(1, 5, 1, 'F122', null),
            self::candidate(1, 5, 2, 'FS1122', null),
        ], 10);

        $this->assertSame([], $chosen);
    }

    public function testSummarizesTheMostViewedBillsFirst(): void
    {
        $chosen = ImpactStatements::toSummarize([
            self::candidate(1, 2, 1, 'HB1F122', self::pdf(1)),
            self::candidate(2, 9, 2, 'HB2F122', self::pdf(2)),
            self::candidate(3, 4, 3, 'HB3F122', self::pdf(3)),
        ], 2);

        $this->assertSame(['HB2F122', 'HB3F122'], array_column($chosen, 'lis_id'));
    }
}
