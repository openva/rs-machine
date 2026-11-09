<?php

namespace RsMachine;

use Memcached;
use PDO;

/**
 * The website's cached bill records, which tasks must clear when they change a bill.
 *
 * The website (Bill2::info()) keeps each bill's record in Memcached for a week, as "bill-<id>":
 * the bill's fields, and its copatrons, tags, status history, text versions, places, duplicates
 * and related bills. A task that changes any of those deletes the record, so that the next page
 * view rebuilds it.
 */
class BillCache
{
    /**
     * The columns of bills that the cached record includes, which fingerprints() compares.
     * (Not hotness or interestingness, say, which cleanup.php recalculates on every run.)
     */
    private const CACHED_COLUMNS = [
        'status', 'outcome', 'date_introduced', 'catch_line', 'summary', 'summary_hash', 'notes',
        'copatrons', 'chief_patron_id', 'last_committee_id', 'incorporated_into',
    ];

    /**
     * Delete these bills' cached records.
     *
     * @param iterable<int|string> $bill_ids
     * @return int How many bills' records were deleted (or weren't cached).
     */
    public static function forget(?Memcached $mc, iterable $bill_ids): int
    {
        $keys = [];
        foreach ($bill_ids as $bill_id) {
            if ((int) $bill_id > 0) {
                $keys['bill-' . (int) $bill_id] = true;
            }
        }
        if ($mc === null || $keys === []) {
            return 0;
        }

        $results = $mc->deleteMulti(array_keys($keys));

        // A record that wasn't cached is as good as deleted.
        return count(array_filter(
            is_array($results) ? $results : [],
            static fn($result) => $result === true || $result === Memcached::RES_NOTFOUND
        ));
    }

    /**
     * A fingerprint of each of a session's bills, of the data that its cached record includes, so
     * that a task that changes bills in bulk (cleanup.php) can tell which it changed, by comparing
     * fingerprints from before and after (changed()).
     *
     * @return array<int, string> Fingerprints, by bill ID.
     */
    public static function fingerprints(PDO $dbh, int $session_id): array
    {
        $parts = [];
        foreach (self::CACHED_COLUMNS as $column) {
            $parts[] = 'IFNULL(bills.' . $column . ', "\0")';
        }
        // The full text is long, so only its hash goes into the fingerprint.
        $parts[] = 'IFNULL(MD5(bills.full_text), "\0")';
        $parts[] = 'IFNULL((SELECT GROUP_CONCAT(tags.tag ORDER BY tags.tag SEPARATOR "\t")
            FROM tags WHERE tags.bill_id = bills.id), "\0")';

        $stmt = $dbh->prepare('SELECT bills.id, MD5(CONCAT_WS("|", ' . implode(', ', $parts) . '))
            FROM bills WHERE bills.session_id = :session_id');
        $stmt->execute([':session_id' => $session_id]);

        $fingerprints = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $fingerprint]) {
            $fingerprints[(int) $id] = $fingerprint;
        }

        return $fingerprints;
    }

    /**
     * The bills whose fingerprints differ, including any added.
     *
     * @param array<int, string> $before
     * @param array<int, string> $after
     * @return int[] Bill IDs.
     */
    public static function changed(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $bill_id => $fingerprint) {
            if (($before[$bill_id] ?? null) !== $fingerprint) {
                $changed[] = $bill_id;
            }
        }

        return $changed;
    }
}
