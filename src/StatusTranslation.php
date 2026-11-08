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
     */
    public static function translate(string $status): ?string
    {
        $status = trim($status);
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
     * @param string[] $same_day The bill's history entries from the same day.
     */
    public static function isFailedMotion(string $status, array $same_day): bool
    {
        $status = trim($status);
        if (self::translate($status) !== 'failed committee') {
            return false;
        }
        // The committee: whatever follows the last "in" or "from", without "Committee" before it,
        // or an amendment or a vote tally after it.
        $pattern = '/^.*\b(?:in|from) (?:Committee )?(.+?)(?: with (?:a )?(?:substitute|amendments?))?\s*(?:\(.*)?$/i';
        if (preg_match($pattern, $status, $matches) !== 1) {
            return false;
        }
        $committee = preg_quote(trim($matches[1]), '/');

        foreach ($same_day as $other) {
            if (preg_match('/^' . self::PREFIX . 'Reported from ' . $committee . '(?:\s*$|\s*\(| with | and (?:re)?referred )/i', trim((string) $other)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate a session's untranslated history entries, and clear the translation of any
     * committee failure that turns out to be a motion that failed (see isFailedMotion()). Then
     * set each of the session's bills' status to that of its latest translated entry.
     *
     * A failure is looked at again on every run, because the entry recording that the committee
     * reported the bill may arrive after it.
     *
     * @return int How many history entries' translations were changed.
     */
    public static function apply(PDO $dbh, int $session_id): int
    {
        $select = $dbh->prepare(
            'SELECT id, bill_id, DATE(date) AS day, status, translation
            FROM bills_status
            WHERE session_id = :session_id'
        );
        $select->execute([':session_id' => $session_id]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        // Each bill's history entries, by day.
        $days = [];
        foreach ($rows as $row) {
            $days[$row['bill_id'] . ' ' . $row['day']][] = (string) $row['status'];
        }

        $update = $dbh->prepare('UPDATE bills_status SET translation = :translation WHERE id = :id');
        $changed = 0;
        foreach ($rows as $row) {
            $same_day = $days[$row['bill_id'] . ' ' . $row['day']];
            if ($row['translation'] === null) {
                $translation = self::translate((string) $row['status']);
                if ($translation === null || self::isFailedMotion((string) $row['status'], $same_day)) {
                    continue;
                }
            } elseif (
                $row['translation'] === 'failed committee'
                && self::isFailedMotion((string) $row['status'], $same_day)
            ) {
                $translation = null;
            } else {
                continue;
            }
            $update->bindValue(':translation', $translation, $translation === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $update->bindValue(':id', (int) $row['id'], PDO::PARAM_INT);
            $update->execute();
            $changed++;
        }

        $bills = $dbh->prepare(
            'UPDATE bills
            SET status = (
                SELECT translation
                FROM bills_status
                WHERE
                    bills_status.bill_id = bills.id AND
                    translation IS NOT NULL
                ORDER BY date DESC, id DESC
                LIMIT 1
            )
            WHERE session_id = :session_id'
        );
        $bills->execute([':session_id' => $session_id]);

        return $changed;
    }
}
