<?php

use PHPUnit\Framework\TestCase;

use RsMachine\BillReferences;

/**
 * How cron/articles.php finds the bills that an article mentions (RsMachine\BillReferences). The
 * current session is 2026's regular session (LIS's 261).
 */
class ArticlesTest extends TestCase
{
    /**
     * The bills that a text mentions, as articles.php finds them.
     */
    private function extractBills(string $text): array
    {
        return BillReferences::find($text, '261', 2026);
    }

    // ------------------------------------------------------------------
    // Abbreviated patterns
    // ------------------------------------------------------------------

    public function testAbbreviatedNoSpace(): void
    {
        $this->assertSame(['hb123'], $this->extractBills('voted on HB123 today'));
    }

    public function testAbbreviatedWithSpace(): void
    {
        $this->assertSame(['sb42'], $this->extractBills('passed SB 42 unanimously'));
    }

    public function testAbbreviatedCaseInsensitive(): void
    {
        $this->assertSame(['hj5'], $this->extractBills('see hj5 for details'));
    }

    public function testAbbreviatedAllPrefixes(): void
    {
        $text = 'HB1 SB2 HJ3 SJ4 HR5 SR6';
        $this->assertSame(['hb1', 'hj3', 'hr5', 'sb2', 'sj4', 'sr6'], $this->extractBills($text));
    }

    // ------------------------------------------------------------------
    // Full name patterns
    // ------------------------------------------------------------------

    public function testFullNameHouseBill(): void
    {
        $this->assertSame(['hb42'], $this->extractBills('House Bill 42 was introduced'));
    }

    public function testFullNameSenateBill(): void
    {
        $this->assertSame(['sb100'], $this->extractBills('Senate Bill 100 passed'));
    }

    public function testFullNameHouseJointResolution(): void
    {
        $this->assertSame(['hj5'], $this->extractBills('House Joint Resolution 5'));
    }

    public function testFullNameSenateJointResolution(): void
    {
        $this->assertSame(['sj10'], $this->extractBills('Senate Joint Resolution 10'));
    }

    public function testFullNameHouseResolution(): void
    {
        $this->assertSame(['hr2'], $this->extractBills('House Resolution 2'));
    }

    public function testFullNameSenateResolution(): void
    {
        $this->assertSame(['sr1'], $this->extractBills('Senate Resolution 1'));
    }

    // ------------------------------------------------------------------
    // Deduplication and mixed patterns
    // ------------------------------------------------------------------

    public function testDuplicatesAreCollapsed(): void
    {
        $text = 'HB123 was introduced. Later HB 123 passed. See House Bill 123.';
        $this->assertSame(['hb123'], $this->extractBills($text));
    }

    public function testMultipleDifferentBills(): void
    {
        $text = 'HB 21 and Senate Bill 347 were discussed alongside SB454';
        $this->assertSame(['hb21', 'sb347', 'sb454'], $this->extractBills($text));
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testNoBillReferences(): void
    {
        $this->assertSame([], $this->extractBills('No legislation mentioned here'));
    }

    public function testBillNumberInUrl(): void
    {
        // URL contains "HB650" — the regex will (correctly) still match it,
        // because the script searches the full HTML content.
        $text = '<a href="https://lis.virginia.gov/bill-details/20261/HB650">House Bill 650</a>';
        $this->assertSame(['hb650'], $this->extractBills($text));
    }

    public function testLinksToThisSessionsBills(): void
    {
        $this->assertSame(['hb1208'], $this->extractBills('<a href="https://lis.virginia.gov/bill-details/20261/HB1208">a bill</a>'));
        $this->assertSame(['sb592'], $this->extractBills('<a href="https://lis.virginia.gov/bill-details/20261/SB592/text/CHAP0347">the law</a>'));
        $this->assertSame(['hj7'], $this->extractBills('<a href="https://www.richmondsunlight.com/bill/2026/hj7/">a resolution</a>'));
    }

    public function testIgnoresLinksToOtherSessionsBills(): void
    {
        // Last year's bill, and this year's special session's.
        $this->assertSame([], $this->extractBills('<a href="https://lis.virginia.gov/bill-details/20251/HB2724?">last year</a>'));
        $this->assertSame([], $this->extractBills('<a href="https://lis.virginia.gov/bill-details/20262/HR2069">a special session</a>'));
        $this->assertSame([], $this->extractBills('<a href="https://www.richmondsunlight.com/bill/2024/hb1/">an old bill</a>'));
        // Nor the bill's text, later in the same link.
        $this->assertSame([], $this->extractBills('<a href="https://lis.virginia.gov/bill-details/20251/HB12/text/HB12">its text</a>'));
        // A bill named in the text still counts, even if its link is to another session's.
        $this->assertSame(['hb5'], $this->extractBills('<a href="https://lis.virginia.gov/bill-details/20251/HB5">HB 5</a>'));
    }

    public function testWordBoundaryPreventsPartialMatch(): void
    {
        // "EXHIBIT100" should not match as "HB" — the \b anchor prevents it.
        $this->assertSame([], $this->extractBills('EXHIBIT100'));
    }

    // ------------------------------------------------------------------
    // RSS fixture integration
    // ------------------------------------------------------------------

    /**
     * Parse the real Virginia Mercury RSS fixture and verify that known
     * bill references are found.
     */
    public function testRssFixtureExtractsBillReferences(): void
    {
        $fixture = __DIR__ . '/data/virginia_mercury_rss.xml';
        $this->assertFileExists($fixture);

        $xml = simplexml_load_string(file_get_contents($fixture));
        $this->assertNotFalse($xml, 'Fixture XML should parse successfully');

        $all_bills = [];

        foreach ($xml->channel->item as $item) {
            $title = (string) $item->title;
            $description = (string) $item->description;

            $content = '';
            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['content'])) {
                $content_ns = $item->children($namespaces['content']);
                $content = (string) $content_ns->encoded;
            }

            $search_text = $title . ' ' . $description . ' ' . $content;
            $bills = $this->extractBills($search_text);

            foreach ($bills as $bill) {
                $all_bills[$bill] = true;
            }
        }

        $found = array_keys($all_bills);
        sort($found);

        // These bill numbers appear in the fixture (verified via grep).
        $expected_present = ['hb217', 'hb650', 'hb1260', 'sb98', 'sb347', 'sb454'];
        foreach ($expected_present as $bill) {
            $this->assertContains($bill, $found, "Expected bill $bill to be found in fixture");
        }

        // We should find a reasonable number of distinct bills.
        $this->assertGreaterThan(20, count($found), 'Should find many distinct bill references');
    }

    /**
     * Verify that content:encoded is parsed — many bill references only
     * appear in the full article body, not the title or description.
     */
    public function testContentEncodedIsUsed(): void
    {
        $fixture = __DIR__ . '/data/virginia_mercury_rss.xml';
        $xml = simplexml_load_string(file_get_contents($fixture));

        // Collect bills found in title+description only, vs. all three fields.
        $without_content = [];
        $with_content = [];

        foreach ($xml->channel->item as $item) {
            $title = (string) $item->title;
            $description = (string) $item->description;

            $without_content += array_flip($this->extractBills($title . ' ' . $description));

            $content = '';
            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['content'])) {
                $content_ns = $item->children($namespaces['content']);
                $content = (string) $content_ns->encoded;
            }

            $with_content += array_flip($this->extractBills($title . ' ' . $description . ' ' . $content));
        }

        $this->assertGreaterThan(
            count($without_content),
            count($with_content),
            'content:encoded should contribute additional bill references'
        );
    }

    /**
     * Verify that every RSS item has the expected fields.
     */
    public function testRssItemsHaveRequiredFields(): void
    {
        $fixture = __DIR__ . '/data/virginia_mercury_rss.xml';
        $xml = simplexml_load_string(file_get_contents($fixture));

        $count = 0;
        foreach ($xml->channel->item as $item) {
            $count++;
            $this->assertNotEmpty((string) $item->title, "Item #$count should have a title");
            $this->assertNotEmpty((string) $item->link, "Item #$count should have a link");
            $this->assertNotEmpty((string) $item->pubDate, "Item #$count should have a pubDate");
            $this->assertNotFalse(
                strtotime((string) $item->pubDate),
                "Item #$count pubDate should be parseable"
            );
        }

        $this->assertGreaterThan(0, $count, 'Fixture should contain at least one item');
    }

    /**
     * Full-name patterns normalise identically to their abbreviated equivalents.
     */
    public function testFullNameAndAbbreviationNormalizeIdentically(): void
    {
        $pairs = [
            ['House Bill 42', 'HB42'],
            ['Senate Bill 100', 'SB100'],
            ['House Joint Resolution 5', 'HJ5'],
            ['Senate Joint Resolution 10', 'SJ10'],
            ['House Resolution 2', 'HR2'],
            ['Senate Resolution 1', 'SR1'],
        ];

        foreach ($pairs as [$full, $abbrev]) {
            $this->assertSame(
                $this->extractBills($full),
                $this->extractBills($abbrev),
                "$full and $abbrev should normalise to the same value"
            );
        }
    }
}
