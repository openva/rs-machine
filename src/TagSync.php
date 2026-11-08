<?php

namespace RsMachine;

/**
 * Which tags to copy between identical bills (bills with the same summary), so that they're all
 * tagged alike, for cleanup.php.
 */
class TagSync
{
    /**
     * The tags that each bill lacks, of those that any bill identical to it has.
     *
     * Tags are compared as the tags table's unique key compares them, ignoring case and trailing
     * spaces, so a bill tagged "Fees" isn't given "fees" too.
     *
     * @param array<int,string[]> $bills Each identical bill's tags, by bill ID.
     * @return array<int,string[]> The tags to add, by bill ID, for the bills that lack any.
     */
    public static function missing(array $bills): array
    {
        if (count($bills) < 2) {
            return [];
        }

        $all = [];
        foreach ($bills as $tags) {
            foreach ($tags as $tag) {
                $tag = rtrim((string) $tag);
                if ($tag !== '') {
                    $all[self::key($tag)] ??= $tag;
                }
            }
        }

        $missing = [];
        foreach ($bills as $bill_id => $tags) {
            $has = array_flip(array_map(static fn($tag) => self::key(rtrim((string) $tag)), $tags));
            $lacks = array_values(array_diff_key($all, $has));
            if ($lacks !== []) {
                $missing[$bill_id] = $lacks;
            }
        }

        return $missing;
    }

    private static function key(string $tag): string
    {
        return mb_strtolower($tag);
    }
}
