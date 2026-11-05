<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * Which bills' histories to fetch again, though LIS's status for them hasn't changed.
 *
 * cron/history.php fetches a bill's history when LIS's status for the bill changes. Events that
 * don't change the status (a subcommittee's recommendation, say, and its vote) would wait for one
 * that does, and anything after a bill's last change of status would never arrive. So each run
 * also fetches a few bills' histories, those fetched longest ago, which covers every bill about
 * once a day.
 */
final class HistoryRefresh
{
    /** How old a bill's history may get, in seconds. */
    public const MAX_AGE = 86400;

    /**
     * How many bills each run refetches. history.php runs 108 times a day, so this covers 4,320
     * bills a day; a session has about 3,600.
     */
    public const PER_RUN = 40;

    /**
     * The bills to refetch: those fetched longest ago, never-fetched first, of those older than
     * the maximum age.
     *
     * @param array<int,int> $lastFetched LIS legislation ID => when its history was last fetched.
     * @param int[]          $candidates  LIS legislation IDs of bills that may be refetched.
     * @return int[]
     */
    public static function due(
        array $lastFetched,
        array $candidates,
        int $now,
        int $limit = self::PER_RUN,
        int $maxAge = self::MAX_AGE
    ): array {
        $due = [];
        foreach ($candidates as $id) {
            $fetched = $lastFetched[$id] ?? 0;
            if ($now - $fetched >= $maxAge) {
                $due[$id] = $fetched;
            }
        }
        asort($due);

        return array_slice(array_keys($due), 0, max(0, $limit));
    }
}
