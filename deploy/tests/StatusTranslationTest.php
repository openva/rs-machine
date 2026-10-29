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
}
