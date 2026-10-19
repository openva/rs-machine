<?php

use PHPUnit\Framework\TestCase;
use RsMachine\CatchLineRepair;

/**
 * LIS's BILLS.CSV replaces every non-ASCII character with "?". These cases are real catch lines
 * from the 2024–2026 CSVs, paired with the intact titles from the LIS API.
 */
class CatchLineRepairTest extends TestCase
{
    public static function damagedTitles(): array
    {
        return [
            'accented letter'  => ['Celebrating the life of James Richard Damar?.',
                                   'Celebrating the life of James Richard Damaré.'],
            'tilde'            => ['Commending Maritza Pi?ero.', 'Commending Maritza Piñero.'],
            'curly apostrophe' => ['Commending Glory?s Bakery.', 'Commending Glory’s Bakery.'],
            'registered mark'  => ['Commending the Charlottesville Area Association of REALTORS?.',
                                   'Commending the Charlottesville Area Association of REALTORS®.'],
            'en dash'          => ['Establishing the Rules for the 2024?2025 Sessions.',
                                   'Establishing the Rules for the 2024–2025 Sessions.'],
        ];
    }

    /**
     * @dataProvider damagedTitles
     */
    public function testRecognizesDamagedCatchLine(string $csv, string $api): void
    {
        $this->assertTrue(CatchLineRepair::isDamaged($csv));
        $this->assertFalse(CatchLineRepair::isDamaged($api));
    }

    /**
     * @dataProvider damagedTitles
     */
    public function testMatchesIntactTitle(string $csv, string $api): void
    {
        $this->assertTrue(CatchLineRepair::matches($csv, $api));
    }

    public function testRejectsTitleThatDiffersOtherwise(): void
    {
        $csv = 'Celebrating the life of James Richard Damar?.';
        $this->assertFalse(CatchLineRepair::matches($csv, 'Celebrating the life of James Damaré.'));
        $this->assertFalse(CatchLineRepair::matches($csv, 'Celebrating the life of James Richard Damar.'));
        // Two non-ASCII characters where the CSV lost only one.
        $this->assertFalse(CatchLineRepair::matches($csv, 'Celebrating the life of James Richard Damaré…'));
    }

    public function testIgnoresWhitespaceDifferences(): void
    {
        $this->assertTrue(CatchLineRepair::matches(
            'Commending Sad?  Thomas. ',
            "Commending Sadé Thomas."
        ));
    }

    public function testPicksMostRecentMatchingVersion(): void
    {
        $response = ['TextsList' => [
            ['VersionDate' => '2026-01-10T00:00:00', 'EditTitle' => 'Commending Glory\'s Bakery.',
             'DraftTitle' => 'Commending Glory’s Bakery.'],
            ['VersionDate' => '2026-03-01T00:00:00', 'EditTitle' => 'Commending Glory’s Bakery!',
             'DraftTitle' => 'Commending Glory´s Bakery.'],
            ['VersionDate' => '2026-02-01T00:00:00', 'EditTitle' => 'Something else entirely.'],
        ]];

        $this->assertSame(
            'Commending Glory´s Bakery.',
            CatchLineRepair::pickTitle('Commending Glory?s Bakery.', $response)
        );
    }

    public function testStripsMarkupFromOlderSessionsTitles(): void
    {
        $response = ['TextsList' => [
            ['VersionDate' => '2024-02-28T00:00:00', 'EditTitle' => null,
             'DraftTitle' => "<p class=ldtitle>Commending Pop&rsquo;s Drive-In.</p>\n"],
        ]];

        $this->assertSame(
            'Commending Pop’s Drive-In.',
            CatchLineRepair::pickTitle('Commending Pop?s Drive-In.', $response)
        );
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $response = ['TextsList' => [
            ['VersionDate' => '2026-01-10T00:00:00', 'EditTitle' => 'A different bill.'],
        ]];

        $this->assertNull(CatchLineRepair::pickTitle('Commending Glory?s Bakery.', $response));
        $this->assertNull(CatchLineRepair::pickTitle('Commending Glory?s Bakery.', []));
    }
}
