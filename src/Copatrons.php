<?php

declare(strict_types=1);

namespace RsMachine;

use PDO;

/**
 * Who LIS's member IDs in Sponsors.csv ("H0173") refer to, for cron/copatrons.php.
 *
 * Copatrons were looked up among current legislators only, so the run after a legislator left
 * office deleted every copatronage of theirs that session. Anyone who served during the session
 * may have copatroned its bills, so they're looked up among those, by the member ID of the term
 * they served it in (which also covers someone who changed chambers).
 */
final class Copatrons
{
    /**
     * Everyone who served during a session, keyed by LIS member ID.
     *
     * @return array<string,array{legislator_id:int,person_id:int}> legislator_id is the
     *         representatives.id that bills_copatrons stores; person_id is the people.id that
     *         bills.chief_patron_id stores.
     */
    public static function legislators(PDO $dbh, int $session_id): array
    {
        $stmt = $dbh->prepare(
            'SELECT t.lis_id, t.chamber, r.id AS legislator_id, p.id AS person_id
            FROM terms t
            JOIN people p ON p.id = t.person_id
            JOIN representatives r ON r.shortname = p.shortname
            JOIN sessions s ON s.id = :session_id
            WHERE
                t.lis_id IS NOT NULL AND
                t.date_started <= GREATEST(s.date_started, CURDATE()) AND
                (t.date_ended IS NULL OR t.date_ended >= s.date_started)
            ORDER BY t.date_started ASC, t.id ASC'
        );
        $stmt->execute([':session_id' => $session_id]);

        $legislators = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $number = (int) preg_replace('/\D/', '', (string) $row['lis_id']);
            if ($number === 0) {
                continue;
            }
            // Of two terms with the same member ID, the later one wins.
            $legislators[self::memberId((string) $row['chamber'], $number)] = [
                'legislator_id' => (int) $row['legislator_id'],
                'person_id' => (int) $row['person_id'],
            ];
        }

        return $legislators;
    }

    /**
     * A LIS member ID as Sponsors.csv writes it: "H0173".
     */
    public static function memberId(string $chamber, int $number): string
    {
        return strtoupper($chamber[0]) . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
