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
     * Translate a session's untranslated history entries, then set each of its bills' status to
     * that of its latest translated entry.
     *
     * @return int How many history entries were translated.
     */
    public static function apply(PDO $dbh, int $session_id): int
    {
        $select = $dbh->prepare(
            'SELECT id, status FROM bills_status WHERE session_id = :session_id AND translation IS NULL'
        );
        $select->execute([':session_id' => $session_id]);

        $update = $dbh->prepare('UPDATE bills_status SET translation = :translation WHERE id = :id');
        $translated = 0;
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $translation = self::translate((string) $row['status']);
            if ($translation !== null) {
                $update->execute([':translation' => $translation, ':id' => $row['id']]);
                $translated++;
            }
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

        return $translated;
    }
}
