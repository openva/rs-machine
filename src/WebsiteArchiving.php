<?php

declare(strict_types=1);

namespace RsMachine;

use DateTimeImmutable;

/**
 * How often legislators' websites are archived in the Wayback Machine, and which are due.
 *
 * Each site is archived monthly, except weekly from May through November of an election year for
 * its legislator's chamber. Every House seat is up in odd-numbered years; every Senate seat is up
 * in every other odd-numbered year (2023, 2027, 2031…).
 */
class WebsiteArchiving
{
    /** A Senate election year, from which the others follow every four years. */
    private const SENATE_ELECTION_YEAR = 2023;

    public static function isElectionYear(string $chamber, int $year): bool
    {
        if ($year % 2 === 0) {
            return false;
        }

        return match ($chamber) {
            'house'  => true,
            'senate' => ($year - self::SENATE_ELECTION_YEAR) % 4 === 0,
            default  => false,
        };
    }

    /**
     * Whether, on this date, a chamber's sites are archived weekly.
     */
    public static function isWeekly(string $chamber, DateTimeImmutable $date): bool
    {
        $month = (int) $date->format('n');

        return $month >= 5 && $month <= 11
            && self::isElectionYear($chamber, (int) $date->format('Y'));
    }

    /**
     * When a site last archived at $last is next due, under the schedule in effect at $now.
     */
    public static function nextDue(string $chamber, DateTimeImmutable $last, DateTimeImmutable $now): DateTimeImmutable
    {
        return $last->modify(self::isWeekly($chamber, $now) ? '+7 days' : '+1 month');
    }

    /**
     * The sites that are due, never-archived ones first, then the longest-waiting.
     *
     * @param array<string, string> $sites    URL => chamber
     * @param array<string, string> $archived URL => when it was last submitted (any date format
     *                                        DateTimeImmutable accepts)
     * @return list<string> URLs
     */
    public static function due(array $sites, array $archived, DateTimeImmutable $now): array
    {
        $due = [];

        foreach ($sites as $url => $chamber) {
            if (!isset($archived[$url])) {
                $due[$url] = 0;
                continue;
            }

            $last = new DateTimeImmutable($archived[$url]);
            if (self::nextDue($chamber, $last, $now) <= $now) {
                $due[$url] = $last->getTimestamp();
            }
        }

        asort($due);

        return array_map('strval', array_keys($due));
    }
}
