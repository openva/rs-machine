<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * Serves the saved LIS API responses in deploy/tests/data/lis_api instead of calling LIS, and
 * answers Import's DNS lookups from a fixed list instead of the network.
 */
class ImportEmailFixtureStub extends Import
{
    /** The domains of the legislature's addresses, which exist. */
    private const EXISTING_DOMAINS = ['house.virginia.gov', 'senate.virginia.gov'];

    /**
     * @param array<string,mixed> $activeMembers
     * @param array<string,mixed> $contacts
     */
    public function __construct(Log $log, private array $activeMembers, private array $contacts)
    {
        parent::__construct($log);
    }

    protected function lis_api_request($path, array $query = [])
    {
        return match ($path) {
            // Return nothing for the single-member lookup, so Import falls back to the roster.
            '/Member/api/getmembersasync' => [],
            '/Member/api/getactivemembersasync' => $this->activeMembers,
            '/Member/api/getmemberscontactinformationlistasync' => $this->contacts,
            default => [],
        };
    }

    protected function emailDomainExists($domain)
    {
        return in_array($domain, self::EXISTING_DOMAINS, true);
    }
}

/**
 * LIS gives each legislator's email address twice: complete as GABEmailAddress, and cut off at
 * 35 characters as the EmailAddress of their mailing address ("senatorsturtevant@senate.virginia.g").
 * Import used the truncated one, and the periodic refresh in cron/legislators.php kept writing it
 * to terms.email (October 2026). Import now validates addresses, including that the domain exists,
 * since a truncated address is usually still well-formed.
 */
class LegislatorEmailTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $activeMembers;

    /** @var array<string,mixed> */
    private static array $contacts;

    public static function setUpBeforeClass(): void
    {
        $base = __DIR__ . '/data/lis_api';
        self::$activeMembers = json_decode(file_get_contents($base . '/active_members_all.json'), true);
        self::$contacts = json_decode(file_get_contents($base . '/contacts_all.json'), true);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function memberProvider(): array
    {
        $contacts = json_decode(file_get_contents(__DIR__ . '/data/lis_api/contacts_all.json'), true);

        $members = [];
        foreach ($contacts['MemberContactInformationList'] as $member) {
            if (trim((string) ($member['GABEmailAddress'] ?? '')) === '') {
                continue;
            }
            $members[$member['MemberNumber']] = [
                $member['ChamberCode'] === 'S' ? 'senate' : 'house',
                $member['MemberNumber'],
                trim($member['GABEmailAddress']),
            ];
        }

        return $members;
    }

    /**
     * @dataProvider memberProvider
     */
    public function testEmailIsTheCompleteAddress(string $chamber, string $memberNumber, string $expected): void
    {
        $legislator = $this->import(self::$contacts)->fetch_legislator_data_api($chamber, $memberNumber);
        $this->assertIsArray($legislator, 'No legislator data for ' . $memberNumber);

        $email = trim((string) $legislator['email']);
        $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), $email . ' is not a valid address');
        $this->assertStringEndsWith('.virginia.gov', strtolower($email), $email . ' is incomplete');
        $this->assertSame($expected, $email);
    }

    public function testATruncatedGabAddressFallsBackToTheContactAddress(): void
    {
        $contacts = $this->withSturtevantEmails(
            'senatorsturtevant@senate.virginia.g',
            'senatorsturtevant@senate.virginia.gov'
        );

        $legislator = $this->import($contacts)->fetch_legislator_data_api('senate', 'S0099');

        $this->assertSame('senatorsturtevant@senate.virginia.gov', $legislator['email']);
    }

    public function testNoEmailWhenEveryAddressIsInvalid(): void
    {
        $contacts = $this->withSturtevantEmails('senatorsturtevant@senate.virginia.g', 'not an address');
        // The roster has the address too, which Import falls back on.
        $activeMembers = self::$activeMembers;
        foreach ($activeMembers['Members'] as &$member) {
            if ($member['MemberNumber'] === 'S0099') {
                $member['GABEmailAddress'] = 'senatorsturtevant@senate.virginia.go';
            }
        }
        unset($member);

        $legislator = $this->import($contacts, $activeMembers)->fetch_legislator_data_api('senate', 'S0099');

        $this->assertIsArray($legislator);
        $this->assertEmpty($legislator['email'] ?? null, 'An invalid address must not be returned.');
    }

    public function testRefreshIgnoresAnInvalidEmail(): void
    {
        // No database is needed: with nothing else to change, update_legislator returns null
        // before it queries.
        $result = $this->import(self::$contacts)->update_legislator([
            'id' => 1,
            'email' => 'senatorsturtevant@senate.virginia.g',
        ]);

        $this->assertNull($result, 'An invalid email address must not be written over a stored one.');
    }

    /**
     * @param array<string,mixed>      $contacts
     * @param array<string,mixed>|null $activeMembers
     */
    private function import(array $contacts, ?array $activeMembers = null): ImportEmailFixtureStub
    {
        $log = new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        };

        return new ImportEmailFixtureStub($log, $activeMembers ?? self::$activeMembers, $contacts);
    }

    /**
     * The saved contact list, with Sen. Sturtevant's GABEmailAddress and contact entries'
     * EmailAddress replaced.
     *
     * @return array<string,mixed>
     */
    private function withSturtevantEmails(string $gab, string $entries): array
    {
        $contacts = self::$contacts;
        foreach ($contacts['MemberContactInformationList'] as &$member) {
            if ($member['MemberNumber'] !== 'S0099') {
                continue;
            }
            $member['GABEmailAddress'] = $gab;
            foreach ($member['ContactInformation'] as &$entry) {
                $entry['EmailAddress'] = $entries;
            }
            unset($entry);
        }
        unset($member);

        return $contacts;
    }
}
