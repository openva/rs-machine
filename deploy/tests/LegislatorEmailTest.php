<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * Serves the saved LIS API responses in deploy/tests/data/lis_api instead of calling LIS.
 */
class ImportEmailFixtureStub extends Import
{
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
}

/**
 * LIS gives each legislator's email address twice: complete as GABEmailAddress, and cut off at
 * 35 characters as the EmailAddress of their mailing address ("senatorsturtevant@senate.virginia.g").
 * Import used the truncated one, and the periodic refresh in cron/legislators.php kept writing it
 * to terms.email (October 2026).
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
        $import = new ImportEmailFixtureStub(new class extends Log {
            public function put($message, $level = 3)
            {
                return true;
            }
        }, self::$activeMembers, self::$contacts);

        $legislator = $import->fetch_legislator_data_api($chamber, $memberNumber);
        $this->assertIsArray($legislator, 'No legislator data for ' . $memberNumber);

        $email = trim((string) $legislator['email']);
        $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), $email . ' is not a valid address');
        $this->assertStringEndsWith('.virginia.gov', strtolower($email), $email . ' is incomplete');
        $this->assertSame($expected, $email);
    }
}
