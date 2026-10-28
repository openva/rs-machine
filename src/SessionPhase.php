<?php

declare(strict_types=1);

namespace RsMachine;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Where a regular session of the General Assembly stands, so that a bill's history can be
 * described correctly: undecided while the session is underway, possibly awaiting the governor
 * after adjournment, and final once the reconvened (veto) session is over.
 *
 * The Constitution of Virginia (Art. IV, § 6) has the General Assembly reconvene on the sixth
 * Wednesday after adjournment to consider the governor's amendments and vetoes. The governor has
 * up to 30 days after that to act on any bill returned to the governor again, so outcomes are
 * treated as final 30 days after the reconvened session begins.
 */
final class SessionPhase
{
    public const IN_SESSION = 'in_session';
    public const ADJOURNED  = 'adjourned';
    public const FINAL      = 'final';

    /** Days after the reconvened session begins by which every outcome is settled. */
    private const DAYS_TO_FINAL = 30;

    private function __construct(
        public readonly string $phase,
        public readonly ?DateTimeImmutable $phaseStarted,
        public readonly ?DateTimeImmutable $reconvenes
    ) {
    }

    /**
     * @param string|null $dateEnded The session's adjournment date (sessions.date_ended), or null
     *                               if it hasn't been recorded, which is treated as in session.
     */
    public static function determine(?string $dateEnded, DateTimeInterface $today): self
    {
        $today = DateTimeImmutable::createFromInterface($today)->setTime(0, 0);

        if ($dateEnded === null || $dateEnded === '' || $dateEnded === '0000-00-00') {
            return new self(self::IN_SESSION, null, null);
        }

        $adjourned = new DateTimeImmutable($dateEnded);
        $reconvenes = self::reconveningDate($adjourned);
        $final = $reconvenes->modify('+' . self::DAYS_TO_FINAL . ' days');

        if ($today <= $adjourned) {
            return new self(self::IN_SESSION, null, $reconvenes);
        }

        if ($today < $final) {
            return new self(self::ADJOURNED, $adjourned->modify('+1 day'), $reconvenes);
        }

        return new self(self::FINAL, $final, $reconvenes);
    }

    /**
     * The sixth Wednesday after adjournment.
     */
    public static function reconveningDate(DateTimeImmutable $adjourned): DateTimeImmutable
    {
        return $adjourned->modify('next wednesday')->modify('+5 weeks');
    }

    /**
     * A narrative written before this date describes an earlier phase, and should be rewritten.
     * Null while the session is underway, when only a change in the bill's status matters.
     */
    public function staleBefore(): ?string
    {
        return $this->phaseStarted?->format('Y-m-d');
    }
}
