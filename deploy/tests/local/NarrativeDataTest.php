<?php

require_once __DIR__ . '/LocalTestCase.php';

use RsMachine\SessionPhase;

/**
 * Bills' status narratives, as written by bills_status_narratives.php and retired by cleanup.php.
 */
class NarrativeDataTest extends LocalTestCase
{
    public function testNoBillHasTwoCurrentNarratives(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT bills.number, COUNT(*) AS current_narratives
                FROM bills_status_narratives
                JOIN bills ON bills.id = bills_status_narratives.bill_id
                WHERE bills_status_narratives.current = "y"
                GROUP BY bills_status_narratives.bill_id
                HAVING COUNT(*) > 1'
            ),
            'bills have more than one current narrative'
        );
    }

    /**
     * cleanup.php retires a narrative when a status is recorded after it was written, and runs
     * hourly. (A day's leeway allows for the sample being exported between those two things.)
     */
    public function testCurrentNarrativesPostdateTheBillsLatestStatus(): void
    {
        $this->assertNone(
            self::rows(
                'SELECT sessions.year, bills.number, narratives.date_created AS written,
                    latest.recorded AS latest_status_recorded
                FROM bills_status_narratives AS narratives
                JOIN bills ON bills.id = narratives.bill_id
                JOIN sessions ON sessions.id = bills.session_id
                JOIN (
                    SELECT bill_id, MAX(date_created) AS recorded
                    FROM bills_status
                    WHERE translation IS NOT NULL
                    GROUP BY bill_id
                ) AS latest ON latest.bill_id = narratives.bill_id
                WHERE
                    narratives.current = "y" AND
                    narratives.date_created < latest.recorded - INTERVAL 1 DAY
                ORDER BY sessions.year, bills.number'
            ),
            'current narratives predate their bill’s latest status'
        );
    }

    /**
     * Once a session's outcomes are final, its narratives are rewritten to describe them, a few
     * an hour. (A week's leeway allows for that, and for the summarizer's threshold of
     * interestingness, below which bills aren't summarized at all.)
     */
    public function testNarrativesOfFinishedSessionsDescribeFinalOutcomes(): void
    {
        $stale = [];
        foreach (self::rows('SELECT id, year, date_ended FROM sessions WHERE suffix IS NULL') as $session) {
            $phase = SessionPhase::determine($session['date_ended'], new DateTimeImmutable('-7 days'));
            if ($phase->phase !== SessionPhase::FINAL) {
                continue;
            }

            $stale = array_merge($stale, self::rows(
                'SELECT :year AS year, bills.number, narratives.date_created AS written
                FROM bills_status_narratives AS narratives
                JOIN bills ON bills.id = narratives.bill_id
                WHERE
                    bills.session_id = :session_id AND
                    bills.interestingness >= 20 AND
                    narratives.current = "y" AND
                    narratives.date_created < :final
                ORDER BY bills.number',
                [
                    ':year' => $session['year'],
                    ':session_id' => $session['id'],
                    ':final' => $phase->staleBefore(),
                ]
            ));
        }

        $this->assertNone($stale, 'current narratives were written before their session’s outcomes were final');
    }
}
