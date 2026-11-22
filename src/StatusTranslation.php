<?php

declare(strict_types=1);

namespace RsMachine;

use PDO;

/**
 * Translates LIS's bill history entries ("Left in Finance", "Read third time and passed House
 * (96-Y 2-N)") into Richmond Sunlight's statuses ("failed committee", "passed house"), which
 * bills_status.translation stores and bills.status shows.
 *
 * These were a series of UPDATE ... LIKE queries in cleanup.php, written for LIS's CSV history,
 * which prefixed many entries with their chamber ("House: Left in Finance"). The LIS API doesn't,
 * so those queries stopped matching, and bills left in committee, passed by indefinitely, or tabled
 * were shown as still in committee. The rules here accept either form.
 *
 * Rules are tried in order, and the first match wins, as when each query only filled in rows that
 * an earlier one hadn't. Matching is case-insensitive, as it was in MariaDB.
 */
final class StatusTranslation
{
    /** An optional chamber prefix, from LIS's CSV history. */
    private const PREFIX = '(?:(?:House|Senate): )?';

    /**
     * @return array<int, array{0: string, 1: string}> Pairs of [translation, regex].
     */
    private static function rules(): array
    {
        $p = self::PREFIX;

        return [
            ['introduced', "/^{$p}Prefiled /"],
            ['introduced', "/^{$p}Presented,? (?:and )?ordered printed/"],
            ['in subcommittee', "/^{$p}Assigned .*sub/"],
            ['in committee', '/^Referred to Committee/'],
            ['in committee', "/^{$p}Committee$/"],
            ['in committee', "/^{$p}Rereferred to/"],
            // A bill continued from the previous year's session, still in the committee it was
            // continued in. Without this, its status would be the previous session's "Continued to
            // 2027 in Rules", which is a committee failure.
            ['in committee', '/^Continued from /'],
            ['failed committee', "/^{$p}Left in /"],
            ['failed committee', "/^{$p}Tabled in /"],
            ['failed committee', "/^{$p}Passed by (?:in |indefinitely)/"],
            ['failed committee', '/Failed to report/'],
            ['failed committee', '/^Continued to |^Continued pursuant to .* to \d{4}/'],
            ['failed committee', '/Stricken from docket/'],
            ['failed subcommittee', '/Subcommittee recommends passing by indefinitely/'],
            ['failed subcommittee', '/Subcommittee recommends laying on the table/'],
            ['failed subcommittee', '/Subcommittee failed to recommend reporting/'],
            ['failed subcommittee', '/Subcommittee recommends striking from the docket/'],
            ['failed subcommittee', '/Subcommittee recommends continuing/'],
            ['passed subcommittee', '/Subcommittee recommends reporting/'],
            ['incorporated', '/Incorporated/'],
            ['stricken', '/Stricken at request of patron/'],
            ['passed committee', "/^{$p}(?:Reported from|Discharged from|Rereferred from) /"],
            ['passed house', '/and passed House|^Agreed to by House|^Passed House/'],
            ['failed house', '/engrossment refused by House/'],
            ['failed house', '/failed to pass in House$|defeated by House/'],
            ['passed senate', '/and passed Senate|^Agreed to by Senate|^Passed Senate/'],
            ['failed senate', '/^Failed to pass in Senate$|defeated by Senate/'],
            ['failed', '/^Failed to pass(?: from conference)?$|^Conference report rejected by /'],
            // The chambers agreed to different conference reports, so neither passed the bill.
            ['failed', '/^Different conference reports? agreed to by each chamber/'],
            ['enacted', '/^Enacted/'],
            ['signed by governor', '/Approved by Governor/'],
            ['signed by governor', "/Governor's recommendation adopted/"],
            ['vetoed by governor', '/Vetoed by Governor/'],
            ['passed', '/Signed by President/'],
            ['enacted', '/veto overridden/'],
        ];
    }

    /**
     * The bill that an incorporation names ("Incorporated by Finance (HB979-Watts)"), as Richmond
     * Sunlight stores bill numbers ("hb979"), or null when it names none.
     */
    public static function incorporatedInto(string $status): ?string
    {
        if (preg_match('/\b([HS](?:JR|B|J|R))\s*0*([1-9]\d*)\b/i', $status, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1] . $matches[2]);
    }

    /**
     * The status that a history entry represents, or null for one that doesn't change it (e.g.
     * "Read second time").
     *
     * A chapter of the Acts of Assembly ("Assigned Chapter 980 (effective 7/1/2026)") makes a bill
     * law, and is sometimes the only record that it became one: SB342 (2026) was returned to the
     * governor after the General Assembly rejected the governor's recommendation, and LIS records
     * no "Approved by Governor". But constitutional amendments are assigned chapters too, and a
     * resolution isn't enacted, so this is a bill's status only when $number says it's a bill.
     *
     * @param ?string $number The bill's number ("sb342"), if known.
     */
    public static function translate(string $status, ?string $number = null): ?string
    {
        $status = trim($status);
        if (preg_match('/^Assigned Chapter/i', $status) === 1) {
            return $number !== null && preg_match('/^[hs]b\d/i', $number) === 1 ? 'enacted' : null;
        }
        foreach (self::rules() as [$translation, $pattern]) {
            if (preg_match($pattern . 'i', $status) === 1) {
                return $translation;
            }
        }
        return null;
    }

    /**
     * Whether a committee failure ("Continued to 2027 in Finance and Appropriations") is a motion
     * that failed, because the same committee reported the bill the same day.
     *
     * LIS records some motions that fail as though they'd happened. Senate committees' histories
     * have "Continued to 2027 in Finance and Appropriations", "Passed by indefinitely in Courts of
     * Justice" or "Failed to report from Courts of Justice with substitute" on the same day as
     * "Reported from" that committee, which is what the committee did.
     *
     * The report comes after the failed motion. When a committee reports a bill and then fails
     * it, the failure is real: SB476 (2026) was "Reported from Commerce and Labor with substitute",
     * then "Passed by indefinitely in Commerce and Labor", and went no further.
     *
     * @param string[] $later The bill's history entries from later the same day, in order.
     */
    public static function isFailedMotion(string $status, array $later): bool
    {
        $status = trim($status);
        $committee = self::failedIn($status);
        if ($committee === null) {
            return false;
        }
        $committee = preg_quote($committee, '/');

        foreach ($later as $other) {
            if (preg_match('/^' . self::PREFIX . 'Reported from ' . $committee . '(?:\s*$|\s*\(| with | and (?:re)?referred )/i', trim((string) $other)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The committee that a committee failure names ("Continued to 2027 in Finance and
     * Appropriations" → "Finance and Appropriations"), or null if the entry isn't one.
     */
    private static function failedIn(string $status): ?string
    {
        $status = trim($status);
        if (self::translate($status) !== 'failed committee') {
            return null;
        }
        // The committee: whatever follows the last "in" or "from", without "Committee" before it,
        // or an amendment or a vote tally after it.
        $pattern = '/^.*\b(?:in|from) (?:Committee )?(.+?)(?: with (?:a )?(?:substitute|amendments?))?\s*(?:\(.*)?$/i';
        if (preg_match($pattern, $status, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * The committee that a referral names ("Referred to Committee on Finance and Appropriations",
     * "Rereferred to Finance"), or null if the entry isn't one.
     */
    private static function referredTo(string $status): ?string
    {
        $pattern = '/^' . self::PREFIX . '(?:Referred to Committee (?:on|for) (?:the )?|Rereferred to )(.+?)\s*(?:\(.*)?$/i';
        if (preg_match($pattern, trim($status), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Translate a session's untranslated history entries, and clear the translation of any
     * committee failure that turns out to be a motion that failed (see isFailedMotion()). Then
     * set each of the session's bills' status to that of its latest translated entry (see
     * statusOf()).
     *
     * A failure is looked at again on every run, because the entry recording that the committee
     * reported the bill may arrive after it.
     *
     * @return int How many history entries' translations were changed.
     */
    public static function apply(PDO $dbh, int $session_id): int
    {
        $select = $dbh->prepare(
            'SELECT bills_status.id, bills_status.bill_id, bills.number, DATE(bills_status.date) AS day,
                bills_status.status, bills_status.translation
            FROM bills_status
            JOIN bills ON bills.id = bills_status.bill_id
            WHERE bills_status.session_id = :session_id
            ORDER BY bills_status.bill_id, bills_status.date, bills_status.id'
        );
        $select->execute([':session_id' => $session_id]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        // Each bill's history entries, by day, in order.
        $days = [];
        foreach ($rows as $row) {
            $days[$row['bill_id'] . ' ' . $row['day']][] = (string) $row['status'];
        }

        $update = $dbh->prepare('UPDATE bills_status SET translation = :translation WHERE id = :id');
        $changed = 0;
        $position = [];
        foreach ($rows as $i => $row) {
            $key = $row['bill_id'] . ' ' . $row['day'];
            $position[$key] = ($position[$key] ?? -1) + 1;
            $later = array_slice($days[$key], $position[$key] + 1);
            if ($row['translation'] === null) {
                $translation = self::translate((string) $row['status'], (string) $row['number']);
                if ($translation === null || self::isFailedMotion((string) $row['status'], $later)) {
                    continue;
                }
            } elseif (
                $row['translation'] === 'failed committee'
                && self::isFailedMotion((string) $row['status'], $later)
            ) {
                $translation = null;
            } else {
                continue;
            }
            $update->bindValue(':translation', $translation, $translation === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $update->bindValue(':id', (int) $row['id'], PDO::PARAM_INT);
            $update->execute();
            $rows[$i]['translation'] = $translation;
            $changed++;
        }

        $histories = [];
        foreach ($rows as $row) {
            $histories[$row['bill_id']][] = $row;
        }

        $bills = $dbh->prepare('SELECT id, status FROM bills WHERE session_id = :session_id');
        $bills->execute([':session_id' => $session_id]);
        $set = $dbh->prepare('UPDATE bills SET status = :status WHERE id = :id');
        foreach ($bills->fetchAll(PDO::FETCH_ASSOC) as $bill) {
            $status = self::statusOf($histories[$bill['id']] ?? []);
            if ($status === $bill['status']) {
                continue;
            }
            $set->bindValue(':status', $status, $status === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $set->bindValue(':id', (int) $bill['id'], PDO::PARAM_INT);
            $set->execute();
        }

        return $changed;
    }

    /**
     * A bill's status: the translation of its latest translated history entry, with two
     * exceptions.
     *
     * LIS records most committee actions with a date but no time (midnight), while the referral
     * that preceded one has a time, so it sorts after the action: HB244 (2026) was "Referred to
     * Committee on Finance and Appropriations" at 3:31 p.m., and "Continued to 2027 in Finance and
     * Appropriations" at midnight. So a referral to a committee doesn't count when the same
     * committee failed the bill that day. (Only a failure: a committee can report a bill that the
     * chamber rerefers to it.)
     *
     * And a chapter assignment doesn't count for a bill that was signed by the governor, which
     * says more.
     *
     * @param array<int, array{day: string, status: string, translation: ?string}> $history The
     *     bill's history entries, in order.
     */
    private static function statusOf(array $history): ?string
    {
        $signed = in_array('signed by governor', array_column($history, 'translation'), true);
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];
            if ($entry['translation'] === null) {
                continue;
            }
            if ($signed && preg_match('/^Assigned Chapter/i', trim((string) $entry['status'])) === 1) {
                continue;
            }
            $referred = self::referredTo((string) $entry['status']);
            if ($referred !== null && self::failedSameDay($history, $entry['day'], $referred)) {
                continue;
            }
            return (string) $entry['translation'];
        }

        return null;
    }

    /**
     * Whether a bill's history has a failure, still translated as one, in a committee on a day.
     *
     * @param array<int, array{day: string, status: string, translation: ?string}> $history
     */
    private static function failedSameDay(array $history, string $day, string $committee): bool
    {
        foreach ($history as $entry) {
            if (
                $entry['day'] === $day
                && $entry['translation'] === 'failed committee'
                && strcasecmp((string) self::failedIn((string) $entry['status']), $committee) === 0
            ) {
                return true;
            }
        }

        return false;
    }
}
