<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

if (!class_exists('NullLog', false)) {
    /**
     * Silent logger for test isolation.
     */
    class NullLog extends Log
    {
        public function put($message, $level)
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
 * Import stub that serves canned LIS API fixtures instead of making HTTP requests.
 */
class ImportFixtureStub extends Import
{
    /** @var array<string,mixed> */
    private $fixtures;

    /** @var array<int,array<string,mixed>> */
    public $requests = [];

    /**
     * @param array<string,mixed> $fixtures
     */
    public function __construct(Log $log, array $fixtures)
    {
        parent::__construct($log);
        $this->fixtures = $fixtures;
    }

    /**
     * Provide fixture responses keyed by member number and path.
     */
    protected function lis_api_request($path, array $query = [])
    {
        $this->requests[] = ['path' => $path, 'query' => $query];

        if ($path === '/Member/api/getmembersasync') {
            $memberNumber = $query['memberNumber'] ?? '';
            return $this->fixtures['members'][$memberNumber] ?? [];
        }

        if ($path === '/Member/api/getactivemembersasync') {
            if (isset($query['chamberCode'])) {
                $code = $query['chamberCode'];
                if ($code === 'S') {
                    return $this->fixtures['active_senate'] ?? [];
                }
                if ($code === 'H') {
                    return $this->fixtures['active_house'] ?? [];
                }
            }
            return $this->fixtures['active_all'] ?? [];
        }

        if ($path === '/Member/api/getmemberscontactinformationlistasync') {
            return $this->fixtures['contacts_all'] ?? [];
        }

        return [];
    }
}

class LegislatorActiveMatchTest extends TestCase
{
    /** @var array<string,mixed> */
    private $fixtures;

    protected function setUp(): void
    {
        $base = __DIR__ . '/data/lis_api';
        $decode = static function ($file) {
            return json_decode(file_get_contents($file), true);
        };

        $this->fixtures = [
            'members' => [
                '85' => $decode($base . '/member_senate_S0085.json'),
                'H0314' => $decode($base . '/member_house_H0314.json'),
            ],
            'contacts_all' => $decode($base . '/contacts_all.json'),
            'active_all' => $decode($base . '/active_members_all.json'),
            'active_house' => $decode($base . '/active_members_house.json'),
            'active_senate' => $decode($base . '/active_members_senate.json'),
        ];
    }

    public function testFetchesCorrectMemberWithNormalizedLisId(): void
    {
        $import = new ImportFixtureStub(new NullLog(), $this->fixtures);

        $result = $import->fetch_legislator_data_api('house', 'H314');

        $this->assertIsArray($result, 'Expected legislator payload for H0314');
        $this->assertSame('H0314', $result['lis_id']);
        $this->assertSame('house', $result['chamber']);
        $this->assertSame('D', $result['party']);
        $this->assertStringContainsString('Cole', $result['name_formal']);

        $memberRequests = array_values(array_filter(
            $import->requests,
            static fn($request) => $request['path'] === '/Member/api/getmembersasync'
        ));
        $this->assertNotEmpty($memberRequests, 'Expected member lookup to be performed');
        $this->assertSame('H0314', $memberRequests[0]['query']['memberNumber']);
    }

    public function testRejectsMismatchedMemberPayload(): void
    {
        $fixtures = $this->fixtures;
        // Serve the senate payload when the house member is requested to simulate a mismatch.
        $fixtures['members']['H0314'] = $fixtures['members']['85'];
        // Remove active lists so the importer cannot fall back to a different roster entry.
        $fixtures['active_all'] = [];
        $fixtures['active_house'] = [];
        $fixtures['active_senate'] = [];

        $import = new ImportFixtureStub(new NullLog(), $fixtures);

        $result = $import->fetch_legislator_data_api('house', 'H0314');

        $this->assertFalse(
            $result,
            'Mismatched member payload should not be merged into the requested legislator'
        );
    }

    public function testDoesNotMergeContactDetailsFromOtherMember(): void
    {
        $fixtures = $this->fixtures;

        // Replace contacts with a different member's details to ensure they are ignored.
        $fixtures['contacts_all'] = [
            'MemberContactInformationList' => [
                [
                    'MemberNumber' => '85',
                    'ContactInformation' => [
                        [
                            'ContactType' => 'District Office',
                            'PhoneNumber' => '(999) 999-9999',
                            'Address1' => '111 Wrong St',
                            'City' => 'Wrongville',
                            'Zip' => '00000'
                        ]
                    ]
                ]
            ]
        ];

        $import = new ImportFixtureStub(new NullLog(), $fixtures);
        $result = $import->fetch_legislator_data_api('house', 'H0314');

        $this->assertIsArray($result);
        $this->assertSame('H0314', $result['lis_id']);
        // Email should come from the member payload, not the unrelated contact entry.
        $this->assertSame('DelJCole@house.virginia.gov', $result['email']);
        // No district contact info should be pulled from the wrong member.
        $this->assertArrayNotHasKey('address_district', $result);
        $this->assertArrayNotHasKey('phone_district', $result);
    }

    public function testRosterDuplicatesAreSkipped(): void
    {
        $fixtures = $this->fixtures;
        // Create a duplicate senate member entry with conflicting name/party to ensure it is ignored.
        $fixtures['active_senate'] = [
            'Members' => [
                [
                    'MemberNumber' => '85',
                    'ListDisplayName' => 'Ebbin, Adam P.',
                    'MemberDisplayName' => 'Adam P. Ebbin',
                    'ChamberCode' => 'S',
                    'DistrictID' => 39,
                    'DistrictName' => '39th',
                    'PartyCode' => 'D',
                    'MemberStatus' => 'Active'
                ],
                [
                    'MemberNumber' => '85',
                    'ListDisplayName' => 'Fake, Person',
                    'MemberDisplayName' => 'Fake Person',
                    'ChamberCode' => 'S',
                    'DistrictID' => 1,
                    'DistrictName' => '1st',
                    'PartyCode' => 'R',
                    'MemberStatus' => 'Active'
                ],
            ],
            'Success' => true
        ];

        $import = new ImportFixtureStub(new NullLog(), $fixtures);
        $senators = $import->fetch_active_members('senate');

        $this->assertCount(1, $senators, 'Duplicate roster entries should be collapsed to one legislator');
        $this->assertSame('85', $senators[0]['lis_id']);
        $this->assertStringContainsString('Ebbin', $senators[0]['name_formal']);
        $this->assertSame('D', $senators[0]['party']);
    }
}
