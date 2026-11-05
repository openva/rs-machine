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

    /**
     * @return array<int,int> LIS legislation ID => when its history was last fetched.
     */
    public static function load(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $lastFetched = [];
        foreach ($decoded as $id => $time) {
            if (is_numeric($id) && is_int($time)) {
                $lastFetched[(int) $id] = $time;
            }
        }

        return $lastFetched;
    }

    /**
     * Save when bills' histories were last fetched, keeping only the given bills, so that the
     * file holds only the current session's.
     *
     * @param array<int,int> $lastFetched
     * @param int[]          $keep LIS legislation IDs to keep.
     */
    public static function save(string $path, array $lastFetched, array $keep): bool
    {
        $lastFetched = array_intersect_key($lastFetched, array_flip($keep));
        ksort($lastFetched);

        // Write to a temporary file and rename it, so a failed write never leaves half a file.
        $temporary = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, json_encode($lastFetched, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT)) === false) {
            return false;
        }

        return rename($temporary, $path);
    }
}
