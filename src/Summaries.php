<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * Which of a bill's summaries in LIS's Summaries.csv is the current one.
 *
 * A bill gets a new summary as it advances: "SUMMARY AS INTRODUCED", "AS PASSED HOUSE" or
 * "AS PASSED SENATE" (once for each chamber), "AS PASSED", and "AS ENACTED WITH GOVERNOR'S
 * RECOMMENDATION". The file is sorted by document ID (HB2S, HB2SCH, HB2SE, HB2SER), not in that
 * order, so the last row for a bill isn't necessarily the latest summary.
 */
final class Summaries
{
    /**
     * The latest summary of each bill.
     *
     * @param array<int,array{number:string,doc_id:string,type:string,text:string}> $rows Rows of
     *        the CSV, in the file's order.
     * @return array<string,array{number:string,doc_id:string,type:string,text:string}> Keyed by
     *        bill number, e.g. "hb1".
     */
    public static function latest(array $rows): array
    {
        $latest = [];
        $ranks = [];
        foreach ($rows as $row) {
            $number = self::billNumber($row['number']);
            $rank = self::rank($row['type'], $number);
            if ($number === null || $rank === null) {
                continue;
            }

            // Of two of the same rank (which LIS hasn't published), the later in the file wins.
            if (!isset($ranks[$number]) || $rank >= $ranks[$number]) {
                $latest[$number] = $row;
                $ranks[$number] = $rank;
            }
        }

        return $latest;
    }

    /**
     * A bill number as Richmond Sunlight stores it: "HB0001" becomes "hb1".
     */
    public static function billNumber(string $number): ?string
    {
        if (preg_match('/^\s*([A-Z]+)0*(\d+)\s*$/i', $number, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]) . $matches[2];
    }

    /**
     * How far along a bill a summary type comes, or null for something that isn't a summary
     * type (as happens in a row that LIS's quoting broke).
     */
    private static function rank(string $type, ?string $number): ?float
    {
        $type = strtoupper(trim($type));
        if (!str_starts_with($type, 'SUMMARY AS ')) {
            return null;
        }
        $stage = substr($type, strlen('SUMMARY AS '));

        if ($stage === 'INTRODUCED') {
            return 0;
        }
        if ($stage === 'PASSED HOUSE' || $stage === 'PASSED SENATE') {
            // The chamber the bill started in passes it first.
            $first = str_starts_with((string) $number, 'h') ? 'PASSED HOUSE' : 'PASSED SENATE';
            return $stage === $first ? 1 : 2;
        }
        if ($stage === 'PASSED') {
            return 3;
        }
        if (str_starts_with($stage, 'ENACTED')) {
            return 4;
        }

        // A type LIS hasn't used before: after introduction, but before anything known.
        return 0.5;
    }
}
