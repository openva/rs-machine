<?php

require_once __DIR__ . '/LocalTestCase.php';

use RsMachine\SessionPhase;

/**
 * Bills, their status histories, and their fiscal impact statements, as written by bills.php,
 * history.php, and fiscal_impact.php.
 */
class BillDataTest extends LocalTestCase
{
    /**
     * Once a session's outcomes are final, every bill has either become law or died, so none can
     * still be in progress. (This is limited to bills, because a resolution's final status depends
     * on whether it needed both chambers.)
     */
    public function testBillsInFinishedSessionsHaveAFinalStatus(): void
    {
        $unfinished = [];
        foreach (self::rows('SELECT id, year, date_ended FROM sessions WHERE suffix IS NULL') as $session) {
            $phase = SessionPhase::determine($session['date_ended'], new DateTimeImmutable('today'));
            if ($phase->phase !== SessionPhase::FINAL) {
                continue;
            }

            $unfinished = array_merge($unfinished, self::rows(
                'SELECT :year AS year, bills.number, bills.status,
                    (
                        SELECT LEFT(bills_status.status, 60)
                        FROM bills_status
                        WHERE
                            bills_status.bill_id = bills.id AND
                            bills_status.status NOT LIKE "Fiscal Impact%" AND
                            bills_status.status NOT LIKE "Bill text%"
                        ORDER BY bills_status.date DESC, bills_status.id DESC
                        LIMIT 1
                    ) AS last_action
                FROM bills
                WHERE
                    bills.session_id = :session_id AND
                    LEFT(bills.number, 2) IN ("hb", "sb") AND
                    bills.status IN ("introduced", "in committee", "passed subcommittee",
                        "passed committee", "passed house", "passed senate", "passed")
                ORDER BY bills.number',
                [':year' => $session['year'], ':session_id' => $session['id']]
            ));
        }

        $this->assertNone($unfinished, 'bills from finished sessions are still in progress');
    }

    public function testStatusHistoryBelongsToTheBillsSession(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT bills.number, bills.session_id AS bill_session,
                    bills_status.session_id AS status_session, COUNT(*) AS statuses
                FROM bills_status
                JOIN bills ON bills.id = bills_status.bill_id
                WHERE bills_status.session_id <> bills.session_id
                GROUP BY bills.id, bills_status.session_id'
            ),
            'bills have status history recorded under another session'
        );
    }

    /**
     * fiscal_impact.php keeps one PDF per reference ID (see RsMachine\ImpactStatements).
     */
    public function testImpactStatementsAreNotDuplicated(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT bills.number, fiscal_impact_statements.lis_id, COUNT(*) AS copies
                FROM fiscal_impact_statements
                JOIN bills ON bills.id = fiscal_impact_statements.bill_id
                GROUP BY fiscal_impact_statements.bill_id, fiscal_impact_statements.lis_id
                HAVING COUNT(*) > 1'
            ),
            'impact statements are stored more than once'
        );
    }
}
