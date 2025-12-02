<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

/**
 * Import stub that feeds controlled member data to exercise shortname generation.
 */
class ImportShortnameStub extends Import
{
    /** @var array<string,mixed> */
    private $member;

    /**
     * @param array<string,mixed> $member
     */
    public function __construct(Log $log, array $member)
    {
        parent::__construct($log);
        $this->member = $member;
    }

    protected function lis_api_request($path, array $query = [])
    {
        if ($path === '/Member/api/getmembersasync') {
            return [
                'Members' => [$this->member],
                'Success' => true,
            ];
        }

        if ($path === '/Member/api/getactivemembersasync') {
            return [
                'Members' => [$this->member],
                'Success' => true,
            ];
        }

        if ($path === '/Member/api/getmemberscontactinformationlistasync') {
            return [
                'MemberContactInformationList' => [],
                'Success' => true,
            ];
        }

        return [];
    }
}

class LegislatorShortnameTest extends TestCase
{
    /**
     * @dataProvider shortnameProvider
     *
     * @param string $memberNumber LIS member number (e.g., H0001).
     * @param string $chamber      Chamber string (house|senate).
     * @param string $memberDisplayName MemberDisplayName value.
     * @param string $listDisplayName   ListDisplayName value.
     * @param string $expectedShortname Expected shortname.
     */
    public function testShortnameGeneration(
        string $memberNumber,
        string $chamber,
        string $memberDisplayName,
        string $listDisplayName,
        string $expectedShortname
    ): void {
        $member = [
            'MemberNumber' => $memberNumber,
            'MemberDisplayName' => $memberDisplayName,
            'ListDisplayName' => $listDisplayName,
            'ChamberCode' => ($chamber === 'senate') ? 'S' : 'H',
            'DistrictID' => 1,
            'DistrictName' => '1st',
            'PartyCode' => 'D',
            'MemberStatus' => 'Active',
        ];

        $import = new ImportShortnameStub(new Log(), $member);
        $legislator = $import->fetch_legislator_data_api($chamber, $memberNumber);

        $this->assertIsArray($legislator, 'Expected legislator data to be returned');
        $this->assertSame($expectedShortname, $legislator['shortname']);
    }

    public static function shortnameProvider(): array
    {
        return [
            'middle initial present' => [
                'H0085',
                'house',
                'Adam P. Ebbin',
                'Ebbin, Adam P.',
                'apebbin',
            ],
            'middle name present' => [
                'H0224',
                'house',
                'James William Morefield',
                'Morefield, Will',
                'jwmorefield',
            ],
            'hyphenated last name' => [
                'H0179',
                'house',
                'Anne B. Crockett-Stark',
                'Crockett-Stark, Anne',
                'abcrockett-stark',
            ],
            'hyphenated last name with middle initial' => [
                'H0334',
                'house',
                'Elizabeth B. Bennett-Parker',
                'Bennett-Parker, Elizabeth',
                'ebbennett-parker',
            ],
            'no middle initial' => [
                'H0344',
                'house',
                'Irene Shin',
                'Shin, Irene',
                'ishin',
            ],
            'two middle initials' => [
                'H0305',
                'house',
                'Kathy K.L. Tran',
                'Tran, Kathy',
                'kkltran',
            ],
            'nickname and suffix' => [
                'H0287',
                'house',
                'Norman Dewey "Rocky" Holcomb II',
                'Holcomb, Rocky',
                'ndholcomb',
            ],
            'nickname and no middle initial' => [
                'H0312',
                'house',
                'Gianfranco "John" Avoli',
                'Avoli, John',
                'gavoli',
            ],
            'camelcase' => [
                'S0129',
                'senate',
                'Schuyler T. VanValkenburg',
                'VanValkenburg, Schuyler',
                'stvanvalkenburg',
            ],
            'camelcase and suffix' => [
                'H0096',
                'house',
                'William R. DeSteph, Jr.',
                'DeSteph, Bill',
                'wrdesteph',
            ],
            'apostrophe in last name' => [
                'H0134',
                'house',
                "John M. O'Bannon",
                "O'Bannon, John",
                'jmobannon',
            ],
        ];
    }
}
