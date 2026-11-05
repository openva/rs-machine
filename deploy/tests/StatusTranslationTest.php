<?php

use PHPUnit\Framework\TestCase;
use RsMachine\StatusTranslation;

require_once __DIR__ . '/../../includes/vendor/autoload.php';

/**
 * Real history entries, from the LIS API (no chamber prefix) and from LIS's CSV history (with one).
 */
class StatusTranslationTest extends TestCase
{
    public static function entries(): array
    {
        return [
            // Bills that died in committee, which the CSV-era rules missed without a prefix
            ['Left in Finance', 'failed committee'],
            ['Left in Committee Public Safety', 'failed committee'],
            ['House: Left in Appropriations', 'failed committee'],
            ['Passed by indefinitely in Transportation (8-Y 6-N)', 'failed committee'],
            ['Passed by indefinitely in Rules (Voice Vote)', 'failed committee'],
            ['Senate: Passed by indefinitely in Finance', 'failed committee'],
            ['Tabled in Appropriations (22-Y 0-N)', 'failed committee'],
            ['Continued to 2027 in Finance and Appropriations', 'failed committee'],
            ['Continued pursuant to House Rule 22 to 2027 in Finance', 'failed committee'],
            ['Failed to report from Courts of Justice with substitute', 'failed committee'],
            ['Stricken from docket by General Laws (22-Y 0-N)', 'failed committee'],

            // Subcommittees, including with a vote tally after the text
            ['Subcommittee recommends laying on the table (10-Y 0-N)', 'failed subcommittee'],
            ['Subcommittee recommends laying on the table', 'failed subcommittee'],
            ['Subcommittee recommends passing by indefinitely (6-Y 2-N)', 'failed subcommittee'],
            ['Subcommittee recommends continuing to 2027 (Voice Vote)', 'failed subcommittee'],
            ['Subcommittee recommends reporting with substitute (9-Y 1-N)', 'passed subcommittee'],

            // Conference committees
            ['Failed to Pass from conference', 'failed'],
            ['Conference report rejected by Senate (16-Y 21-N 0-A)', 'failed'],

            // Introduction and referral
            ['Prefiled and ordered printed; Offered 01-14-2026 26100044D', 'introduced'],
            ['House: Prefiled and ordered printed; offered 01/10/24', 'introduced'],
            ['Presented and ordered printed 26100444D', 'introduced'],
            ['Presented, ordered printed (HR391)', 'introduced'],
            ['Assigned HAPP sub: Transportation & Public Safety', 'in subcommittee'],
            ['Referred to Committee on Labor and Commerce', 'in committee'],
            ['Committee', 'in committee'],
            ['Rereferred to Finance', 'in committee'],
            ['Reported from General Laws with substitute (20-Y 1-N)', 'passed committee'],
            ['Rereferred from Courts of Justice to Finance and Appropriations', 'passed committee'],

            // Floor votes and the governor
            ['Read third time and passed House (71-Y 27-N 0-A)', 'passed house'],
            ['Passed Senate with amendments (21-Y 19-N 0-A)', 'passed senate'],
            ['Read third time and defeated by House (34-Y 63-N 0-A)', 'failed house'],
            ['Defeated by Senate', 'failed senate'],
            ['Incorporated by Finance (HB979-Watts)  (Voice Vote)', 'incorporated'],
            ['Stricken at request of Patron in Commerce and Labor', 'stricken'],
            ['Signed by President', 'passed'],
            ['Approved by Governor-Chapter 981 (effective 7/1/26)', 'signed by governor'],
            ['Vetoed by Governor', 'vetoed by governor'],

            // Entries that don't change a bill's status
            ['Passed by for the day', null],
            ['Read second time', null],
            ['Fiscal Impact statement From TAX (2/1/2026 10:04 am)', null],
            ['Conference report agreed to by Senate (24-Y 15-N 0-A)', null],
        ];
    }

    /**
     * @dataProvider entries
     */
    public function testTranslate(string $status, ?string $expected): void
    {
        $this->assertSame($expected, StatusTranslation::translate($status));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function incorporations(): array
    {
        return [
            'a House bill'         => ['Incorporated by Finance (HB979-Watts)  (Voice Vote)', 'hb979'],
            'a Senate bill'        => ['Incorporated by Finance and Appropriations (SB94-Roem)  (13-Y 2-N)', 'sb94'],
            'a joint resolution'   => ['Incorporated by Rules (HJ12-Smith)', 'hj12'],
            'a resolution'         => ['Incorporated by Rules (SR3-Jones)', 'sr3'],
            'an older form'        => ['House: Incorporated into HJR 45', 'hjr45'],
            'a padded number'      => ['Incorporated by Courts of Justice (SB0007)', 'sb7'],
            'no bill named'        => ['Incorporated by Finance', null],
            'a vote, not a bill'   => ['Incorporated by Finance (22-Y 0-N)', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incorporations')]
    public function testIncorporatedInto(string $status, ?string $expected): void
    {
        $this->assertSame($expected, StatusTranslation::incorporatedInto($status));
    }

    /**
     * Committee failures, with the bill's other history entries from that day. The first four
     * are SB382, HB916, SB673 and SB283 (2026).
     *
     * @return array<string, array{0: string, 1: string[], 2: bool}>
     */
    public static function failedMotions(): array
    {
        return [
            'continued, then reported' => [
                'Continued to 2027 in Finance and Appropriations',
                ['Fiscal Impact Statement from Department of Planning and Budget (SB382)',
                 'Reported from Finance and Appropriations with substitute (11-Y 4-N)'],
                true,
            ],
            'reported without a tally' => [
                'Continued to 2027 in Finance and Appropriations',
                ['Reported from Finance and Appropriations with substitute'],
                true,
            ],
            'passed by, then reported and rereferred' => [
                'Passed by indefinitely in Courts of Justice',
                ['Reported from Courts of Justice with substitute and rereferred to Finance and Appropriations (12-Y 3-N)'],
                true,
            ],
            'failed to report one version, then reported another' => [
                'Failed to report from Courts of Justice with substitute',
                ['Reported from Courts of Justice with substitute (9-Y 6-N)'],
                true,
            ],
            'with "Committee" before the name' => [
                'Left in Committee Public Safety',
                ['Reported from Public Safety (12-Y 10-N)'],
                true,
            ],
            'from the CSV, with a prefix' => [
                'Senate: Passed by indefinitely in Rules',
                ['Senate: Reported from Rules (10-Y 5-N)'],
                true,
            ],
            'reported from a different committee' => [
                'Left in Appropriations',
                ['Reported from Courts of Justice and referred to Appropriations (15-Y 7-N)'],
                false,
            ],
            'a committee whose name begins the same' => [
                'Left in Finance',
                ['Reported from Finance and Appropriations (10-Y 5-N)'],
                false,
            ],
            'nothing else that day' => [
                'Continued to 2027 in Rules',
                ['Fiscal Impact Statement from Department of Planning and Budget (HB1)'],
                false,
            ],
            'not a committee failure' => [
                'Subcommittee recommends laying on the table (10-Y 0-N)',
                ['Reported from Appropriations'],
                false,
            ],
        ];
    }

    /**
     * @param string[] $same_day
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failedMotions')]
    public function testIsFailedMotion(string $status, array $same_day, bool $expected): void
    {
        $this->assertSame($expected, StatusTranslation::isFailedMotion($status, $same_day));
    }
}
