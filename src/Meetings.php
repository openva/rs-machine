<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * The meetings of the House and Senate in LIS's schedule (the API's GetScheduleListAsync), as rows
 * of the `meetings` table, for [cron/meetings.php](../cron/meetings.php).
 *
 * The schedule lists every meeting LIS knows about since 2022: commissions and councils as well as
 * the legislature, past and future, cancelled or not. Only upcoming meetings of the House, the
 * Senate, and their committees and caucuses are kept.
 *
 * The website (richmondsunlight.com's schedule.php, committee.php and Day class) reads these rows,
 * and parses `description`, which is the schedule's owner and type ("House Finance Committee",
 * "Senate Convenes Other", "House Republican Caucus Caucus"), so that format must not change.
 */
final class Meetings
{
    /** The time zone of LIS's dates and times. */
    public const TIMEZONE = 'America/New_York';

    /** `timedesc` for a meeting whose time LIS hasn't given in any form. */
    public const TIME_UNKNOWN = 'Time to be announced';

    /** The length of `meetings.location` and `meetings.timedesc`. */
    private const MAX_LENGTH = 120;

    /**
     * Committees, indexed for matching to the schedule.
     *
     * @param array<int,array{id:int|string,lis_id:int|string,chamber:string,name:string,parent:?string}> $rows
     *        Current committees; `parent` is the parent committee's name, for a subcommittee.
     * @return array{numbers:array<string,int>,names:array<string,int>} Committee IDs by LIS
     *        committee number ("H14", "H14003"), and by normalized name ("house appropriations").
     */
    public static function committeeIndex(array $rows): array
    {
        $numbers = [];
        $names = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $chamber = strtolower((string) $row['chamber']);
            if (!in_array($chamber, ['house', 'senate'], true)) {
                continue;
            }

            // LIS numbers a committee "H14", and its subcommittees "H14001" and on; Richmond
            // Sunlight stores 14 and 14001.
            $lis_id = (int) $row['lis_id'];
            $digits = empty($row['parent'])
                ? str_pad((string) $lis_id, 2, '0', STR_PAD_LEFT)
                : str_pad((string) $lis_id, 5, '0', STR_PAD_LEFT);
            $numbers[strtoupper($chamber[0]) . $digits] = $id;

            $name = empty($row['parent'])
                ? $chamber . ' ' . $row['name']
                : $chamber . ' ' . $row['parent'] . ' ' . $row['name'];
            $names[self::normalizeName($name)] = $id;
        }

        return ['numbers' => $numbers, 'names' => $names];
    }

    /**
     * A meeting in the schedule as a row of `meetings`, or null if it isn't one to keep.
     *
     * @param array<string,mixed> $schedule One of the API's `Schedules`.
     * @param array{numbers:array<string,int>,names:array<string,int>} $committees From
     *        committeeIndex().
     * @return array{date:string,time:?string,timedesc:?string,description:string,location:string,committee_id:?int}|null
     */
    public static function fromSchedule(array $schedule, array $committees): ?array
    {
        if (!empty($schedule['IsCancelled']) || (isset($schedule['IsPublic']) && $schedule['IsPublic'] === false)) {
            return null;
        }

        $owner = self::plainText((string) ($schedule['OwnerName'] ?? ''));
        $type = trim((string) ($schedule['ScheduleType'] ?? ''));
        $number = strtoupper(trim((string) ($schedule['CommitteeNumber'] ?? '')));
        $date = substr((string) ($schedule['ScheduleDate'] ?? ''), 0, 10);
        if ($owner === '' || $type === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        // Only the legislature's meetings, not commissions' and councils'.
        if (preg_match('/^(House|Senate)\b/i', $owner) !== 1 && preg_match('/^[HS]\d/', $number) !== 1) {
            return null;
        }

        // The schedule marks each chamber's adjournment, which isn't a meeting.
        $raw = (string) ($schedule['Description'] ?? '');
        if (preg_match('/\badjourned$/i', $owner) === 1 || stripos($raw, 'not meeting') !== false) {
            return null;
        }

        [$time, $timedesc] = self::when((string) ($schedule['ScheduleTime'] ?? ''), $raw);

        return [
            'date'         => $date,
            'time'         => $time,
            'timedesc'     => $timedesc,
            'description'  => $owner . ' ' . $type,
            'location'     => self::truncate(self::plainText((string) ($schedule['RoomDescription'] ?? ''))),
            'committee_id' => self::committeeId($number, $owner, $committees),
        ];
    }

    /**
     * A clock time ("8:30 AM", "8:30 a.m.", "10 AM") as the database stores it ("08:30:00").
     */
    public static function clockTime(string $time): ?string
    {
        if (preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\.?\s*$/i', $time, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) ($matches[2] !== '' ? $matches[2] : 0);
        if ($hour < 1 || $hour > 12 || $minute > 59) {
            return null;
        }

        $pm = strtolower($matches[3]) === 'p';
        $hour = ($hour % 12) + ($pm ? 12 : 0);

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    /**
     * When a meeting is: a clock time, or else a description of when ("30 minutes after
     * adjournment of the Senate").
     *
     * LIS gives the time either way in `ScheduleTime`; or leaves it empty, describing the time on
     * the first line of `Description`.
     *
     * @return array{0:?string,1:?string} The time, and the description of the time.
     */
    public static function when(string $schedule_time, string $description): array
    {
        $time = self::clockTime($schedule_time);
        if ($time !== null) {
            return [$time, null];
        }

        $text = self::plainText($schedule_time);
        if ($text === '') {
            $lines = preg_split('/\R/', self::removeLinks($description)) ?: [];
            $first = self::plainText((string) ($lines[0] ?? ''));
            if (preg_match('/\b(?:adjourn|recess|after|before|upon|immediately)|\bTBA\b|to be announced/i', $first) === 1) {
                $text = $first;
            }
        }

        $text = trim($text, " ()");
        if ($text === '') {
            return [null, self::TIME_UNKNOWN];
        }
        if (preg_match('/^TBA$/i', $text) === 1) {
            return [null, self::TIME_UNKNOWN];
        }

        return [null, self::truncate(ucfirst($text))];
    }

    /**
     * Which of the meetings stored for today and later to delete, and which meetings to add, so
     * that they're those in the schedule.
     *
     * This replaces a meeting that LIS reschedules, removes one that it cancels, and removes
     * duplicates.
     *
     * @param array<int,array<string,mixed>> $scheduled Rows from fromSchedule().
     * @param array<int,array<string,mixed>> $stored Rows of `meetings`, with their `id`.
     * @param string $today The date in Virginia, e.g. "2026-01-14".
     * @return array{insert:array<int,array<string,mixed>>,delete:array<int,int>}
     */
    public static function changes(array $scheduled, array $stored, string $today): array
    {
        $wanted = [];
        foreach ($scheduled as $meeting) {
            if ((string) $meeting['date'] >= $today) {
                $wanted[self::key($meeting)] ??= $meeting;
            }
        }

        $kept = [];
        $delete = [];
        foreach ($stored as $row) {
            if ((string) $row['date'] < $today) {
                continue;
            }
            $key = self::key($row);
            if (isset($wanted[$key]) && !isset($kept[$key])) {
                $kept[$key] = true;
            } else {
                $delete[] = (int) $row['id'];
            }
        }

        return [
            'insert' => array_values(array_diff_key($wanted, $kept)),
            'delete' => $delete,
        ];
    }

    /**
     * What identifies a meeting, comparing a row from the schedule with one from the database
     * (where the time is "08:30:00", and the committee ID a string).
     */
    public static function key(array $meeting): string
    {
        $time = $meeting['time'] ?? null;

        return implode("\x1F", [
            (string) $meeting['date'],
            $time === null ? '' : substr((string) $time, 0, 5),
            (string) ($meeting['timedesc'] ?? ''),
            (string) $meeting['description'],
            (string) ($meeting['location'] ?? ''),
            (string) ($meeting['committee_id'] ?? ''),
        ]);
    }

    /**
     * The committee that holds a meeting: by its LIS committee number, or else by the longest
     * committee name the schedule's owner starts with ("House Appropriations - Elementary and
     * Secondary Education Subcommittee" is House Appropriations').
     */
    private static function committeeId(string $number, string $owner, array $committees): ?int
    {
        if ($number !== '' && isset($committees['numbers'][$number])) {
            return $committees['numbers'][$number];
        }

        $owner = self::normalizeName($owner);
        $best = null;
        $best_length = 0;
        foreach ($committees['names'] as $name => $id) {
            $name = (string) $name;
            if (($owner === $name || str_starts_with($owner, $name . ' ')) && strlen($name) > $best_length) {
                $best = $id;
                $best_length = strlen($name);
            }
        }

        return $best;
    }

    /**
     * A committee's name, for comparison: lowercase, with "&" as "and", and without the words
     * "committee" and "subcommittee", or punctuation.
     */
    private static function normalizeName(string $name): string
    {
        $name = mb_strtolower(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/\b(?:sub)?committee(?:\s+(?:on|for))?\b/u', ' ', $name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $name);

        return trim((string) $name);
    }

    private static function removeLinks(string $html): string
    {
        return (string) preg_replace('#<a\b[^>]*>.*?</a>#is', '', $html);
    }

    /** HTML as plain text on one line, with non-breaking spaces as spaces. */
    private static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim((string) $text);
    }

    private static function truncate(string $text): string
    {
        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
