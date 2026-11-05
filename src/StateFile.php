<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * A JSON file in cron/ mapping IDs to integers, which a task keeps between runs (e.g., when each
 * bill's history was last fetched).
 */
final class StateFile
{
    /**
     * @return array<int,int> Empty when the file is missing or damaged.
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

        $map = [];
        foreach ($decoded as $id => $value) {
            if (is_numeric($id) && is_int($value)) {
                $map[(int) $id] = $value;
            }
        }

        return $map;
    }

    /**
     * Save the map, keeping only the given IDs, so that the file holds only what's still current
     * (e.g., this session's bills).
     *
     * @param array<int,int> $map
     * @param int[]          $keep
     */
    public static function save(string $path, array $map, array $keep): bool
    {
        $map = array_intersect_key($map, array_flip($keep));
        ksort($map);

        // Write to a temporary file and rename it, so a failed write never leaves half a file.
        $temporary = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, json_encode($map, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT)) === false) {
            return false;
        }

        return rename($temporary, $path);
    }
}
