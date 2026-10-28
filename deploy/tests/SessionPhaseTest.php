<?php

use PHPUnit\Framework\TestCase;
use RsMachine\SessionPhase;

require_once __DIR__ . '/../../includes/vendor/autoload.php';

class SessionPhaseTest extends TestCase
{
    /**
     * The 2026 session adjourned on March 14 (stored as the 15th), and reconvened April 22.
     */
    public function testReconvenesOnTheSixthWednesdayAfterAdjournment(): void
    {
        $this->assertSame(
            '2026-04-22',
            SessionPhase::reconveningDate(new DateTimeImmutable('2026-03-15'))->format('Y-m-d')
        );
        // The 2025 session adjourned February 22 and reconvened April 2.
        $this->assertSame(
            '2025-04-02',
            SessionPhase::reconveningDate(new DateTimeImmutable('2025-02-22'))->format('Y-m-d')
        );
        // Adjourning on a Wednesday doesn't count that Wednesday.
        $this->assertSame(
            '2025-04-09',
            SessionPhase::reconveningDate(new DateTimeImmutable('2025-02-26'))->format('Y-m-d')
        );
    }

    public function testDuringTheSession(): void
    {
        $phase = SessionPhase::determine('2026-03-15', new DateTimeImmutable('2026-03-15 14:00'));

        $this->assertSame(SessionPhase::IN_SESSION, $phase->phase);
        $this->assertNull($phase->staleBefore());
    }

    public function testAfterAdjournmentBeforeOutcomesAreFinal(): void
    {
        $phase = SessionPhase::determine('2026-03-15', new DateTimeImmutable('2026-04-22'));

        $this->assertSame(SessionPhase::ADJOURNED, $phase->phase);
        $this->assertSame('2026-03-16', $phase->staleBefore());
        $this->assertSame('2026-04-22', $phase->reconvenes->format('Y-m-d'));
    }

    /**
     * Narratives of the 2026 session written before May 22, 2026 predate final outcomes.
     */
    public function testOnceOutcomesAreFinal(): void
    {
        $phase = SessionPhase::determine('2026-03-15', new DateTimeImmutable('2026-10-28'));

        $this->assertSame(SessionPhase::FINAL, $phase->phase);
        $this->assertSame('2026-05-22', $phase->staleBefore());
    }

    public function testUnrecordedAdjournmentMeansInSession(): void
    {
        $this->assertSame(SessionPhase::IN_SESSION, SessionPhase::determine(null, new DateTimeImmutable())->phase);
        $this->assertSame(SessionPhase::IN_SESSION, SessionPhase::determine('0000-00-00', new DateTimeImmutable())->phase);
    }
}
