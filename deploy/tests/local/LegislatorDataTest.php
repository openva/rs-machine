<?php

require_once __DIR__ . '/LocalTestCase.php';

/**
 * Legislators, their terms, and their committee assignments, as written by legislators.php and
 * committee_members.php.
 */
class LegislatorDataTest extends LocalTestCase
{
    /**
     * A person without a term is listed on the website as a current legislator, with no photo.
     * (Import::add_legislator() creates people and terms together, so this means a failed import
     * or a duplicate record.)
     */
    public function testEveryPersonHasATerm(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT people.id, people.shortname, people.date_created
                FROM people
                WHERE NOT EXISTS (SELECT 1 FROM terms WHERE terms.person_id = people.id)
                ORDER BY people.id'
            ),
            'people have no term'
        );
    }

    public function testEveryTermBelongsToAPerson(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT terms.id, terms.person_id, terms.lis_id, terms.chamber
                FROM terms
                LEFT JOIN people ON people.id = terms.person_id
                WHERE people.id IS NULL
                ORDER BY terms.id'
            ),
            'terms belong to nobody'
        );
    }

    /**
     * Each LIS ID identifies one sitting legislator in a chamber. Two current terms with the same
     * one means a legislator was added twice.
     */
    public function testNoLisIdIsHeldByTwoCurrentTerms(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT chamber, lis_id, GROUP_CONCAT(person_id ORDER BY person_id) AS person_ids
                FROM terms
                WHERE date_started <= CURDATE() AND (date_ended IS NULL OR date_ended >= CURDATE())
                GROUP BY chamber, lis_id
                HAVING COUNT(*) > 1'
            ),
            'LIS IDs are held by more than one current term'
        );
    }

    /**
     * committee_members.representative_id is a person's ID, and a current member must be serving
     * in the committee's chamber. This fails when a term's ID is stored in place of a person's,
     * or when memberships aren't ended after their members leave office.
     */
    public function testCurrentCommitteeMembersServeInThatChamber(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT committee_members.id, committee_members.representative_id AS person_id,
                    people.shortname, committees.chamber, committees.name AS committee,
                    committee_members.date_started
                FROM committee_members
                JOIN committees ON committees.id = committee_members.committee_id
                LEFT JOIN people ON people.id = committee_members.representative_id
                WHERE
                    committee_members.date_ended IS NULL AND
                    NOT EXISTS (
                        SELECT 1
                        FROM terms
                        WHERE
                            terms.person_id = committee_members.representative_id AND
                            terms.chamber = committees.chamber AND
                            terms.date_started <= CURDATE() AND
                            (terms.date_ended IS NULL OR terms.date_ended >= CURDATE())
                    )
                ORDER BY committee_members.date_started, committee_members.id'
            ),
            'current committee memberships are held by people not serving in that chamber'
        );
    }

    /**
     * The legacy representatives table, which vote.php and copatrons.php look legislators up in by
     * LIS ID, must match each legislator's current term (representatives_table.php syncs it daily).
     */
    public function testRepresentativesMatchCurrentTerms(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT people.shortname, terms.chamber, terms.lis_id,
                    representatives.chamber AS listed_chamber, representatives.lis_id AS listed_lis_id
                FROM terms
                JOIN people ON people.id = terms.person_id
                LEFT JOIN representatives ON representatives.shortname = people.shortname
                WHERE
                    terms.date_started <= CURDATE() AND
                    (terms.date_ended IS NULL OR terms.date_ended >= CURDATE()) AND
                    (
                        representatives.id IS NULL OR
                        representatives.chamber <> terms.chamber OR
                        representatives.lis_id <> terms.lis_id
                    )
                ORDER BY people.shortname'
            ),
            'current legislators are missing from the representatives table, or listed with an old term'
        );
    }

    /**
     * A bill's chief patron must have been serving when its session began. (Bills are prefiled
     * before the session, and so can predate their patron's term by a few days.)
     */
    public function testBillPatronsServedDuringTheirSession(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT sessions.year, bills.number, bills.chief_patron_id
                FROM bills
                JOIN sessions ON sessions.id = bills.session_id
                WHERE
                    bills.chief_patron_id IS NOT NULL AND
                    NOT EXISTS (
                        SELECT 1
                        FROM terms
                        WHERE
                            terms.person_id = bills.chief_patron_id AND
                            terms.date_started <= GREATEST(
                                COALESCE(bills.date_introduced, sessions.date_started),
                                sessions.date_started
                            ) AND
                            (terms.date_ended IS NULL OR terms.date_ended >= sessions.date_started)
                    )
                ORDER BY sessions.year, bills.number'
            ),
            'bills have a chief patron who wasn’t serving'
        );
    }
}
