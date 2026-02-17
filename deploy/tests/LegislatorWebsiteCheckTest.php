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
     * Sample HTML representative of the House member page.
     * Includes names that appear in recent log warnings so the tests reflect
     * the real scenario that motivated this change.
     */
    private const HOUSE_HTML = '<html><body>
        <h1>Members of the House of Delegates</h1>
        <ul class="member-list">
            <li><a href="/members/knight">Knight, Barry D.</a></li>
            <li><a href="/members/mcquinn">McQuinn, Delores L.</a></li>
            <li><a href="/members/morefield">Morefield, James W. (Will)</a></li>
            <li><a href="/members/watts">Watts, Vivian E.</a></li>
            <li><a href="/members/wright">Wright, Thomas C.</a></li>
        </ul>
    </body></html>';

    /**
     * Sample HTML representative of the Senate member page.
     */
    private const SENATE_HTML = '<html><body>
        <h1>Members of the Senate</h1>
        <table>
            <tr><td><a href="/Senator/aird">Lashrecse D. Aird</a></td></tr>
            <tr><td><a href="/Senator/boysko">Jennifer B. Boysko</a></td></tr>
            <tr><td><a href="/Senator/ebbin">Adam P. Ebbin</a></td></tr>
            <tr><td><a href="/Senator/pillion">Todd E. Pillion</a></td></tr>
        </table>
    </body></html>';

    // -------------------------------------------------------------------------
    // House website: member found / not found
    // -------------------------------------------------------------------------

    public function testHouseMemberFoundOnWebsiteReturnsTrue(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $this->assertTrue($import->legislator_in_lis('H0100', 'Watts, Vivian'));
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

        $this->assertTrue($import->legislator_in_lis('S0050', 'Pillion, Todd'));
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

        $import->legislator_in_lis('H0100', 'Watts, Vivian');

        $this->assertArrayHasKey(self::HOUSE_URL, $import->fetch_counts);
        $this->assertArrayNotHasKey(self::SENATE_URL, $import->fetch_counts);
    }

    public function testSenateUrlFetchedForSenateMember(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [
            self::HOUSE_URL  => self::HOUSE_HTML,
            self::SENATE_URL => self::SENATE_HTML,
        ]);

        $import->legislator_in_lis('S0050', 'Pillion, Todd');

        $this->assertArrayHasKey(self::SENATE_URL, $import->fetch_counts);
        $this->assertArrayNotHasKey(self::HOUSE_URL, $import->fetch_counts);
    }

    // -------------------------------------------------------------------------
    // Caching: the page is fetched only once per chamber per run
    // -------------------------------------------------------------------------

    public function testHousePageFetchedOnlyOnceAcrossMultipleCalls(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML]);

        $import->legislator_in_lis('H0100', 'Watts, Vivian');
        $import->legislator_in_lis('H0200', 'Wright, Thomas');
        $import->legislator_in_lis('H0300', 'Knight, Barry');

        $this->assertSame(
            1,
            $import->fetch_counts[self::HOUSE_URL] ?? 0,
            'House member page should be fetched only once regardless of how many members are checked'
        );
    }

    public function testSenatePageFetchedOnlyOnceAcrossMultipleCalls(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::SENATE_URL => self::SENATE_HTML]);

        $import->legislator_in_lis('S0050', 'Pillion, Todd');
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
                'MemberNumber'   => 'H0100',
                'MemberStatus'   => 'Active',
                'ServiceEndDate' => null,
            ]],
            'Success' => true,
        ];
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => false], $api_payload);

        $this->assertTrue($import->legislator_in_lis('H0100', 'Watts, Vivian'));
    }

    public function testWebsiteFailureFallsBackToApiAndReturnsFalse(): void
    {
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => false], []);

        $this->assertFalse($import->legislator_in_lis('H0100', 'Watts, Vivian'));
    }

    // -------------------------------------------------------------------------
    // Backward compatibility: no name provided skips website and uses API
    // -------------------------------------------------------------------------

    public function testNoNameSkipsWebsiteAndUsesApi(): void
    {
        $api_payload = [
            'Members' => [[
                'MemberNumber'   => 'H0100',
                'MemberStatus'   => 'Active',
                'ServiceEndDate' => null,
            ]],
            'Success' => true,
        ];
        $import = new GaWebsiteStub(new NullLog(), [self::HOUSE_URL => self::HOUSE_HTML], $api_payload);

        $this->assertTrue($import->legislator_in_lis('H0100'));
        $this->assertEmpty($import->fetch_counts, 'Website should not be queried when no name is provided');
    }
}
