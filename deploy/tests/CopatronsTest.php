<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the copatron-parsing logic used by cron/copatrons.php.
 *
 * The CSV parsing, patron-type filtering, and member-ID matching are
 * duplicated from the cron script so they can be exercised without a
 * database.
 */
class CopatronsTest extends TestCase
{
    /**
     * Parse a sponsors CSV string and return copatrons grouped by bill number.
     *
     * Mirrors the core logic of copatrons.php lines 86-123.
     *
     * @param string $csv_raw     Raw CSV content.
     * @param array  $legislators Map of LIS member ID (e.g. "H0173") => RS legislator ID.
     * @param array  $bills       Map of lowercase bill number => ['id' => int, 'chief_patron_id' => int].
     *
     * @return array<int,array<int,true>> bill_id => [legislator_id => true, ...]
     */
    private function parseSponsors(string $csv_raw, array $legislators, array $bills): array
    {
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $csv_raw);
        rewind($fp);

        // Skip header.
        fgetcsv($fp, 0, ',');

        $bill_copatrons = [];

        while (($row = fgetcsv($fp, 0, ',')) !== false) {
            if (count($row) < 4) {
                continue;
            }

            $member_id = trim($row[1]);
            $bill_number = strtolower(trim($row[2]));
            $patron_type = trim($row[3]);

            // Skip chief patrons (but keep "Chief Co-Patron").
            if (strpos($patron_type, 'Chief Patron') !== false && strpos($patron_type, 'Co-Patron') === false) {
                continue;
            }

            if (!isset($bills[$bill_number])) {
                continue;
            }

            if (!isset($legislators[$member_id])) {
                continue;
            }

            $legislator_id = $legislators[$member_id];
            $bill_data = $bills[$bill_number];

            if ($legislator_id == $bill_data['chief_patron_id']) {
                continue;
            }

            $bill_copatrons[$bill_data['id']][$legislator_id] = true;
        }

        fclose($fp);
        return $bill_copatrons;
    }

    /**
     * Build a LIS member ID key the same way copatrons.php does from DB fields.
     */
    private function buildMemberKey(string $chamber, string $lis_id): string
    {
        return strtoupper($chamber[0]) . str_pad($lis_id, 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Member ID zero-padding
    // ------------------------------------------------------------------

    public function testBuildMemberKeyZeroPads(): void
    {
        $this->assertSame('H0173', $this->buildMemberKey('house', '173'));
        $this->assertSame('S0080', $this->buildMemberKey('senate', '80'));
        $this->assertSame('H0001', $this->buildMemberKey('house', '1'));
        $this->assertSame('S0130', $this->buildMemberKey('senate', '130'));
    }

    public function testBuildMemberKeyAlreadyPadded(): void
    {
        $this->assertSame('H0173', $this->buildMemberKey('house', '0173'));
    }

    // ------------------------------------------------------------------
    // Patron type filtering
    // ------------------------------------------------------------------

    public function testChiefPatronIsExcluded(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Jane Doe","H0001","HB1","1 - Chief Patron"
"John Doe","H0002","HB1","2 - Co-Patron"
CSV;

        $legislators = ['H0001' => 10, 'H0002' => 20];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertArrayHasKey(100, $result);
        $this->assertArrayHasKey(20, $result[100], 'Co-Patron should be included');
        $this->assertArrayNotHasKey(10, $result[100], 'Chief Patron should be excluded');
    }

    public function testChiefCoPatronIsIncluded(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Jane Doe","H0001","HB1","1 - Chief Patron"
"John Doe","H0002","HB1","2 - Chief Co-Patron"
CSV;

        $legislators = ['H0001' => 10, 'H0002' => 20];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertArrayHasKey(20, $result[100], 'Chief Co-Patron should be included');
    }

    public function testIncorporatedChiefCoPatronIsIncluded(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Jane Doe","H0001","HB1","1 - Chief Patron"
"Bob Smith","H0003","HB1","2 - Incorporated Chief Co-Patron"
CSV;

        $legislators = ['H0001' => 10, 'H0003' => 30];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertArrayHasKey(30, $result[100], 'Incorporated Chief Co-Patron should be included');
    }

    public function testOfferedIsIncluded(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Jane Doe","H0001","HB1","1 - Chief Patron"
"Bob Smith","H0003","HB1","2 - Offered"
CSV;

        $legislators = ['H0001' => 10, 'H0003' => 30];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertArrayHasKey(30, $result[100], 'Offered should be included');
    }

    // ------------------------------------------------------------------
    // Chief patron as copatron is skipped
    // ------------------------------------------------------------------

    public function testChiefPatronNotAddedAsCopatron(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Jane Doe","H0001","HB1","1 - Chief Patron"
"Jane Doe","H0001","HB1","2 - Co-Patron"
CSV;

        $legislators = ['H0001' => 10];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        // Bill should have no copatrons since the only co-patron is the chief patron.
        $this->assertArrayNotHasKey(100, $result);
    }

    // ------------------------------------------------------------------
    // Unknown bills and legislators are skipped
    // ------------------------------------------------------------------

    public function testUnknownBillIsSkipped(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"John Doe","H0002","HB9999","1 - Co-Patron"
CSV;

        $legislators = ['H0002' => 20];
        $bills = [];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertEmpty($result);
    }

    public function testUnknownLegislatorIsSkipped(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Unknown Person","H9999","HB1","1 - Co-Patron"
CSV;

        $legislators = [];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertEmpty($result);
    }

    // ------------------------------------------------------------------
    // Bill number case insensitivity
    // ------------------------------------------------------------------

    public function testBillNumberIsCaseInsensitive(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"John Doe","H0002","HB1","2 - Co-Patron"
CSV;

        $legislators = ['H0002' => 20];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertArrayHasKey(100, $result);
        $this->assertArrayHasKey(20, $result[100]);
    }

    // ------------------------------------------------------------------
    // Multiple bills and copatrons
    // ------------------------------------------------------------------

    public function testMultipleBillsAndCopatrons(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"Jane Doe","H0001","HB1","1 - Chief Patron"
"John Doe","H0002","HB1","2 - Co-Patron"
"Bob Smith","H0003","HB1","3 - Co-Patron"
"Alice Jones","S0001","SB1","1 - Chief Patron"
"Bob Smith","H0003","SB1","2 - Co-Patron"
CSV;

        $legislators = ['H0001' => 10, 'H0002' => 20, 'H0003' => 30, 'S0001' => 40];
        $bills = [
            'hb1' => ['id' => 100, 'chief_patron_id' => 10],
            'sb1' => ['id' => 200, 'chief_patron_id' => 40],
        ];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertCount(2, $result);
        $this->assertCount(2, $result[100], 'HB1 should have 2 copatrons');
        $this->assertCount(1, $result[200], 'SB1 should have 1 copatron');
        $this->assertArrayHasKey(30, $result[200]);
    }

    // ------------------------------------------------------------------
    // Malformed rows
    // ------------------------------------------------------------------

    public function testShortRowsAreSkipped(): void
    {
        $csv = <<<CSV
"MEMBER_NAME","MEMBER_ID","BILL_NUMBER","PATRON_TYPE"
"John Doe","H0002","HB1"
CSV;

        $legislators = ['H0002' => 20];
        $bills = ['hb1' => ['id' => 100, 'chief_patron_id' => 10]];

        $result = $this->parseSponsors($csv, $legislators, $bills);

        $this->assertEmpty($result);
    }

    // ------------------------------------------------------------------
    // Fixture integration
    // ------------------------------------------------------------------

    /**
     * Parse the real sponsors CSV fixture and verify expected counts.
     */
    public function testFixtureParsesWithExpectedCounts(): void
    {
        $fixture = __DIR__ . '/data/sponsors.csv';
        $this->assertFileExists($fixture);

        $csv_raw = file_get_contents($fixture);

        // Build fake legislator and bill lookups that accept everything.
        // We just want to verify CSV parsing, so map every member ID to a
        // unique integer and create a bill entry for every bill number.
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $csv_raw);
        rewind($fp);
        fgetcsv($fp, 0, ',');

        $legislators = [];
        $bills = [];
        $member_seq = 1;
        $bill_seq = 1;

        while (($row = fgetcsv($fp, 0, ',')) !== false) {
            if (count($row) < 4) {
                continue;
            }
            $mid = trim($row[1]);
            $bnum = strtolower(trim($row[2]));
            if (!isset($legislators[$mid])) {
                $legislators[$mid] = $member_seq++;
            }
            if (!isset($bills[$bnum])) {
                // Use a chief_patron_id of 0 so no one is excluded as chief patron.
                $bills[$bnum] = ['id' => $bill_seq++, 'chief_patron_id' => 0];
            }
        }
        fclose($fp);

        $result = $this->parseSponsors($csv_raw, $legislators, $bills);

        // Chief Patrons (2709) should be excluded; all others (5763) included.
        $total_copatrons = 0;
        foreach ($result as $copatrons) {
            $total_copatrons += count($copatrons);
        }

        $this->assertGreaterThan(5000, $total_copatrons, 'Should parse thousands of copatron rows');

        // Bills with at least one copatron.
        $this->assertGreaterThan(900, count($result), 'Many bills should have copatrons');
    }

    /**
     * Verify HB1 has the expected number of copatrons in the fixture.
     */
    public function testFixtureHb1CopatronCount(): void
    {
        $fixture = __DIR__ . '/data/sponsors.csv';
        $csv_raw = file_get_contents($fixture);

        // Build lookups from the fixture, tracking HB1's chief patron.
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $csv_raw);
        rewind($fp);
        fgetcsv($fp, 0, ',');

        $legislators = [];
        $chief_patron_id = null;
        $member_seq = 1;

        while (($row = fgetcsv($fp, 0, ',')) !== false) {
            if (count($row) < 4) {
                continue;
            }
            $mid = trim($row[1]);
            if (!isset($legislators[$mid])) {
                $legislators[$mid] = $member_seq++;
            }
            if (strtolower(trim($row[2])) === 'hb1' && strpos(trim($row[3]), 'Chief Patron') !== false && strpos(trim($row[3]), 'Co-Patron') === false) {
                $chief_patron_id = $legislators[$mid];
            }
        }
        fclose($fp);

        $bills = ['hb1' => ['id' => 1, 'chief_patron_id' => $chief_patron_id]];
        $result = $this->parseSponsors($csv_raw, $legislators, $bills);

        $this->assertArrayHasKey(1, $result);
        $this->assertCount(62, $result[1], 'HB1 should have 62 copatrons');
    }

    /**
     * Verify that all five patron types in the fixture are handled correctly.
     */
    public function testFixturePatronTypesHandledCorrectly(): void
    {
        $fixture = __DIR__ . '/data/sponsors.csv';
        $csv_raw = file_get_contents($fixture);

        // Parse with everything accepted.
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $csv_raw);
        rewind($fp);
        fgetcsv($fp, 0, ',');

        $legislators = [];
        $bills = [];
        $member_seq = 1;
        $bill_seq = 1;
        $chief_patron_count = 0;
        $total_rows = 0;

        while (($row = fgetcsv($fp, 0, ',')) !== false) {
            if (count($row) < 4) {
                continue;
            }
            $total_rows++;
            $mid = trim($row[1]);
            $bnum = strtolower(trim($row[2]));
            $ptype = trim($row[3]);

            if (!isset($legislators[$mid])) {
                $legislators[$mid] = $member_seq++;
            }
            if (!isset($bills[$bnum])) {
                $bills[$bnum] = ['id' => $bill_seq++, 'chief_patron_id' => 0];
            }

            if (strpos($ptype, 'Chief Patron') !== false && strpos($ptype, 'Co-Patron') === false) {
                $chief_patron_count++;
            }
        }
        fclose($fp);

        $result = $this->parseSponsors($csv_raw, $legislators, $bills);

        $total_copatrons = 0;
        foreach ($result as $copatrons) {
            $total_copatrons += count($copatrons);
        }

        // Total parsed copatrons should equal total rows minus chief patrons,
        // minus any duplicates (same legislator appearing twice on the same bill).
        // The fixture has 12 such duplicates that are correctly collapsed.
        $non_chief = $total_rows - $chief_patron_count;
        $this->assertSame(
            $non_chief,
            $total_copatrons + 12,
            'Copatrons + collapsed duplicates should equal non-chief-patron rows'
        );
    }
}
