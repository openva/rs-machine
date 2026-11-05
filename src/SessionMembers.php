<?php

declare(strict_types=1);

namespace RsMachine;

use PDO;

/**
 * Who LIS's member IDs ("H0173") refer to during a session, for copatrons and votes.
 *
 * LIS identifies legislators by member ID, and Richmond Sunlight by person ID (people.id), which
 * is what bills.chief_patron_id, bills_copatrons.legislator_id, representatives_votes.
 * representative_id, committee_members.representative_id and representatives.id all hold.
 *
 * A member ID is looked up among everyone who served during the session, by the term they served
 * it in, not just among current legislators: that would lose the copatronages and votes of anyone
 * who has since left office, and miss the earlier member ID of anyone who changed chambers.
 */
final class SessionMembers
{
    /**
     * Everyone who served during a session.
     *
     * @return array<string,int> LIS member ID => person ID.
     */
    public static function lookUp(PDO $dbh, int $session_id): array
    {
        $stmt = $dbh->prepare(
            'SELECT t.lis_id, t.chamber, t.person_id
            FROM terms t
            JOIN sessions s ON s.id = :session_id
            WHERE
                t.lis_id IS NOT NULL AND
                t.date_started <= GREATEST(s.date_started, CURDATE()) AND
                (t.date_ended IS NULL OR t.date_ended >= s.date_started)
            ORDER BY t.date_started ASC, t.id ASC'
        );
        $stmt->execute([':session_id' => $session_id]);

        $members = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $number = (int) preg_replace('/\D/', '', (string) $row['lis_id']);
            if ($number === 0) {
                continue;
            }
            // Of two terms with the same member ID, the later one wins.
            $members[self::memberId((string) $row['chamber'], $number)] = (int) $row['person_id'];
        }

        return $members;
    }

    /**
     * A LIS member ID as LIS writes it: "H0173". Accepts a chamber ("house") or its initial.
     */
    public static function memberId(string $chamber, int $number): string
    {
        return strtoupper($chamber[0]) . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
