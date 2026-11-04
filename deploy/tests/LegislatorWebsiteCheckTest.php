<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

if (!class_exists('NullLog', false)) {
    class NullLog extends Log
    {
        public function put($message, $level = 3)
        {
            return true;
        }

        public function filesystem($message)
        {
            return true;
        }
    }
}

/**
 * Import stub that intercepts HTTP requests at the network boundary.
 *
 * Overriding http_get() rather than fetch_ga_member_page() means the
 * production caching logic in fetch_ga_member_page() runs as normal, making
 * the caching assertions meaningful. LIS API calls are also interceptable via
 * lis_api_request().
 */
class GaWebsiteStub extends Import
{
    /** @var array<string,string|false> Maps URL to raw HTML (or false to simulate failure). */
    private $url_responses;

    /** @var array<string,int> How many times each URL was actually fetched. */
    public $fetch_counts = [];

    /** @var array LIS API payload returned for any API request (used for fallback tests). */
    private $api_payload;

    /**
     * @param array<string,string|false> $url_responses Keyed by full URL.
     * @param array                      $api_payload   Returned for any LIS API call.
     */
    public function __construct(Log $log, array $url_responses, array $api_payload = [])
    {
        parent::__construct($log);
        $this->url_responses = $url_responses;
        $this->api_payload   = $api_payload;
    }

    protected function http_get($url)
    {
        $this->fetch_counts[$url] = ($this->fetch_counts[$url] ?? 0) + 1;
        $response = $this->url_responses[$url] ?? false;
        return $response;
    }

    protected function lis_api_request($path, array $query = [])
    {
        return $this->api_payload;
    }
}

class LegislatorWebsiteCheckTest extends TestCase
{
    private const HOUSE_URL  = 'https://house.vga.virginia.gov/members';
    private const SENATE_URL = 'https://apps.senate.virginia.gov/Senator/index.php';

    /**
     * Excerpts of https://house.vga.virginia.gov/members, which links to each member by their ID.
     * Two members share a surname.
     */
    private const HOUSE_HTML = '<html><body>
        <h1>Members of the House of Delegates</h1>
        <ul class="member-list">
            <li><a href="/members/H0206">Knight, Barry D.</a></li>
            <li><a href="/members/H0207">McQuinn, Delores L.</a></li>
            <li><a href="/members/H0108">Watts, Vivian E.</a></li>
            <li><a href="/members/H0342">Scott, Phillip A.</a></li>
            <li><a href="/members/H0136">Wright, Thomas C.</a></li>
        </ul>
    </body></html>';

    /**
     * Excerpts of https://apps.senate.virginia.gov/Senator/index.php, which links to each member
     * by their ID (with trailing spaces).
     */
    private const SENATE_HTML = '<html><body>
        <h1>Members of the Senate</h1>
        <table>
            <tr><td><a href="//apps.senate.virginia.gov/Senator/memberpage.php?id=S113 ">Lashrecse D. Aird</a></td></tr>
            <tr><td><a href="//apps.senate.virginia.gov/Senator/memberpage.php?id=S85  ">Adam P. Ebbin</a></td></tr>
            <tr><td><a href="//apps.senate.virginia.gov/Senator/memberpage.php?id=S100 ">Todd E. Pillion</a></td></tr>
        </table>
    </body></html>';

    // -------------------------------------------------------------------------
    // House website: member found / not found
    // -------------------------------------------------------------------------

    public function testHouseMemberFoundOnWebsiteReturnsTrue(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $this->assertTrue($import->legislator_in_lis('H0108', 'Watts, Vivian'));
    }

    public function testHouseMemberNotOnWebsiteReturnsFalse(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $this->assertFalse($import->legislator_in_lis('H0200', 'Departed, Jane'));
    }

    // -------------------------------------------------------------------------
    // Senate website: member found / not found
    // -------------------------------------------------------------------------

    public function testSenateMemberFoundOnWebsiteReturnsTrue(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::SENATE_URL => self::SENATE_HTML]);

        $this->assertTrue($import->legislator_in_lis('S0100', 'Pillion, Todd'));
    }

    public function testSenateMemberNotOnWebsiteReturnsFalse(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::SENATE_URL => self::SENATE_HTML]);

        $this->assertFalse($import->legislator_in_lis('S0050', 'Departed, Jane'));
    }

    // -------------------------------------------------------------------------
    // Correct URL is requested for each chamber
    // -------------------------------------------------------------------------

    public function testHouseUrlFetchedForHouseMember(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [
            self::HOUSE_URL  => self::HOUSE_HTML,
            self::SENATE_URL => self::SENATE_HTML,
        ]);

        $import->legislator_in_lis('H0108', 'Watts, Vivian');

        $this->assertArrayHasKey(self::HOUSE_URL, $import->fetch_counts);
        $this->assertArrayNotHasKey(self::SENATE_URL, $import->fetch_counts);
    }

    public function testSenateUrlFetchedForSenateMember(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [
            self::HOUSE_URL  => self::HOUSE_HTML,
            self::SENATE_URL => self::SENATE_HTML,
        ]);

        $import->legislator_in_lis('S0100', 'Pillion, Todd');

        $this->assertArrayHasKey(self::SENATE_URL, $import->fetch_counts);
        $this->assertArrayNotHasKey(self::HOUSE_URL, $import->fetch_counts);
    }

    // -------------------------------------------------------------------------
    // Caching: the page is fetched only once per chamber per run
    // -------------------------------------------------------------------------

    public function testHousePageFetchedOnlyOnceAcrossMultipleCalls(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $import->legislator_in_lis('H0108', 'Watts, Vivian');
        $import->legislator_in_lis('H0136', 'Wright, Thomas');
        $import->legislator_in_lis('H0206', 'Knight, Barry');

        $this->assertSame(
            1,
            $import->fetch_counts[self::HOUSE_URL] ?? 0,
            'House member page should be fetched only once regardless of how many members are checked'
        );
    }

    public function testSenatePageFetchedOnlyOnceAcrossMultipleCalls(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::SENATE_URL => self::SENATE_HTML]);

        $import->legislator_in_lis('S0100', 'Pillion, Todd');
        $import->legislator_in_lis('S0085', 'Ebbin, Adam');

        $this->assertSame(
            1,
            $import->fetch_counts[self::SENATE_URL] ?? 0,
            'Senate member page should be fetched only once regardless of how many members are checked'
        );
    }

    // -------------------------------------------------------------------------
    // Fallback to LIS API when the website cannot be fetched
    // -------------------------------------------------------------------------

    public function testWebsiteFailureFallsBackToApiAndReturnsTrue(): void
    {
        $api_payload = [
            'Members' => [[
                'MemberNumber'   => 'H0108',
                'MemberStatus'   => 'Active',
                'ServiceEndDate' => null,
            ]],
            'Success' => true,
        ];
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => false], $api_payload);

        $this->assertTrue($import->legislator_in_lis('H0108', 'Watts, Vivian'));
    }

    public function testWebsiteFailureFallsBackToApiAndReturnsFalse(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => false], []);

        $this->assertFalse($import->legislator_in_lis('H0108', 'Watts, Vivian'));
    }

    // -------------------------------------------------------------------------
    // Members are found by their ID, not their name
    // -------------------------------------------------------------------------

    /**
     * A departed delegate who shares a surname with one still serving is not still listed.
     */
    public function testADepartedMemberWhoSharesASurnameIsntListed(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $this->assertFalse($import->legislator_in_lis('H0155', 'Scott, Donald'));
    }

    /**
     * A member's ID doesn't match one that merely begins with it (S10 vs. S100).
     */
    public function testAnIdIsntMatchedByALongerOne(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::SENATE_URL => self::SENATE_HTML]);

        $this->assertFalse($import->legislator_in_lis('S0010', 'Departed, Jane'));
        $this->assertTrue($import->legislator_in_lis('S0085', 'Ebbin, Adam'));
    }

    public function testTheWebsiteIsCheckedWithoutAName(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $this->assertTrue($import->legislator_in_lis('H0108'));
        $this->assertSame(1, $import->fetch_counts[self::HOUSE_URL] ?? 0);
    }

    /**
     * A page that links to nobody by ID has changed its layout; it isn't evidence that everybody
     * has left office.
     */
    public function testAPageWithoutMemberLinksFallsBackToTheApi(): void
    {
        $api_payload = [
            'Members' => [[
                'MemberNumber'   => 'H0108',
                'MemberStatus'   => 'Active',
                'ServiceEndDate' => null,
            ]],
            'Success' => true,
        ];
        $page = '<html><body><p>Watts, Vivian E.</p></body></html>';
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => $page], $api_payload);

        $this->assertTrue($import->legislator_in_lis('H0108', 'Watts, Vivian'));
    }
}
