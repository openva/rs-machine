<?php

use PHPUnit\Framework\TestCase;
use RsMachine\WebsiteArchiving;

class WebsiteArchivingTest extends TestCase
{
    public static function electionYears(): array
    {
        return [
            'House, odd year'              => ['house', 2027, true],
            'House, another odd year'      => ['house', 2029, true],
            'House, even year'             => ['house', 2026, false],
            'Senate, election year'        => ['senate', 2027, true],
            'Senate, next election year'   => ['senate', 2031, true],
            'Senate, House-only odd year'  => ['senate', 2029, false],
            'Senate, even year'            => ['senate', 2028, false],
        ];
    }

    /**
     * @dataProvider electionYears
     */
    public function testIsElectionYear(string $chamber, int $year, bool $expected): void
    {
        $this->assertSame($expected, WebsiteArchiving::isElectionYear($chamber, $year));
    }

    public static function weeklyDates(): array
    {
        return [
            'April of an election year'       => ['house', '2027-04-30', false],
            'May of an election year'         => ['house', '2027-05-01', true],
            'November of an election year'    => ['house', '2027-11-30', true],
            'December of an election year'    => ['house', '2027-12-01', false],
            'Summer of a non-election year'   => ['house', '2026-07-01', false],
            'Senate, House-only election'     => ['senate', '2029-07-01', false],
            'Senate, Senate election'         => ['senate', '2027-07-01', true],
        ];
    }

    /**
     * @dataProvider weeklyDates
     */
    public function testIsWeekly(string $chamber, string $date, bool $expected): void
    {
        $this->assertSame($expected, WebsiteArchiving::isWeekly($chamber, new DateTimeImmutable($date)));
    }

    public function testMonthlyOutsideElectionSeason(): void
    {
        $now = new DateTimeImmutable('2026-10-29 12:00');
        $this->assertSame(
            '2026-11-15',
            WebsiteArchiving::nextDue('house', new DateTimeImmutable('2026-10-15'), $now)->format('Y-m-d')
        );
    }

    public function testWeeklyInElectionSeason(): void
    {
        $now = new DateTimeImmutable('2027-06-10 12:00');
        $this->assertSame(
            '2027-06-08',
            WebsiteArchiving::nextDue('house', new DateTimeImmutable('2027-06-01'), $now)->format('Y-m-d')
        );
    }

    /**
     * A site archived in April is due once election season begins, rather than a month later.
     */
    public function testElectionSeasonShortensAPendingWait(): void
    {
        $sites = ['https://house.example' => 'house', 'https://senate.example' => 'senate'];
        $archived = ['https://house.example' => '2029-04-25', 'https://senate.example' => '2029-04-25'];

        $this->assertSame(
            ['https://house.example'],
            WebsiteArchiving::due($sites, $archived, new DateTimeImmutable('2029-05-03'))
        );
    }

    public function testDueListsNewSitesFirstThenTheLongestWaiting(): void
    {
        $sites = [
            'https://recent.example' => 'house',
            'https://older.example'  => 'house',
            'https://oldest.example' => 'senate',
            'https://new.example'    => 'senate',
        ];
        $archived = [
            'https://recent.example' => '2026-10-20T10:00:00-04:00',
            'https://older.example'  => '2026-09-15T10:00:00-04:00',
            'https://oldest.example' => '2026-08-01T10:00:00-04:00',
        ];

        $this->assertSame(
            ['https://new.example', 'https://oldest.example', 'https://older.example'],
            WebsiteArchiving::due($sites, $archived, new DateTimeImmutable('2026-10-29'))
        );
    }
}
