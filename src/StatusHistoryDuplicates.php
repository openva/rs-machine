<?php

declare(strict_types=1);

namespace RsMachine;

use PDO;

/**
 * Bills' history entries that are stored more than once, and merging them, for
 * utilities/merge_status_duplicates.php.
 *
 * An entry is the same entry if it has the same text on the same day (see key()). Until
 * richmondsunlight.com's Import::store_status_history() matched entries that way, it matched them
 * on their exact text and time, so an entry was stored again whenever either changed: LIS's CSV
 * dated entries at midnight, and began some with a space, and its API gives them times, and adds
 * "Block Vote" to some, so refetching 2025's history from the API (on November 16, 2026) stored
 * most of it twice; and LIS changes some entries' times, which did the same during sessions.
 * Import::store_status_history() must match entries as key() does.
 *
 * Each set of copies is merged into the first recorded, which keeps its ID and when it was
 * recorded (date_created), since that's how cleanup.php tells which statuses are new. It takes the
 * wording of the last copy recorded, which is LIS's current wording, the time of the last copy
 * recorded that has one (not midnight), since LIS's latest time is the most accurate, and any
 * translation, chamber, or vote ID that it lacks, from the latest copy with one.
 */
final class StatusHistoryDuplicates
{
    /**
     * What identifies an entry, with its day: its text, without "Block Vote" or surrounding or
     * repeated spaces.
     */
    public static function key(string $status, string $date): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', str_replace('Block Vote', '', $status)));

        return $text . '|' . substr($date, 0, 10);
    }

    /**
     * How to merge one entry's copies.
     *
     * @param list<array<string,mixed>> $copies Rows of bills_status (id, status, date, translation,
     *                                          chamber, lis_vote_id, date_created): one entry's.
     * @return array{keep:int,set:array<string,mixed>,delete:list<int>}
     */
    public static function merge(array $copies): array
    {
        usort($copies, static fn($a, $b) => [(string) $a['date_created'], (int) $a['id']]
            <=> [(string) $b['date_created'], (int) $b['id']]);
        $keep = $copies[0];
        $latestFirst = array_reverse($copies);

        $set = [];
        if ($latestFirst[0]['status'] !== $keep['status']) {
            $set['status'] = $latestFirst[0]['status'];
        }
        foreach ($latestFirst as $copy) {
            if (!str_ends_with((string) $copy['date'], ' 00:00:00')) {
                if ($copy['date'] !== $keep['date']) {
                    $set['date'] = $copy['date'];
                }
                break;
            }
        }
        foreach (['translation', 'chamber', 'lis_vote_id'] as $column) {
            if (($keep[$column] ?? null) !== null) {
                continue;
            }
            foreach ($latestFirst as $copy) {
                if (($copy[$column] ?? null) !== null) {
                    $set[$column] = $copy[$column];
                    break;
                }
            }
        }

        return [
            'keep'   => (int) $keep['id'],
            'set'    => $set,
            'delete' => array_map(static fn($copy) => (int) $copy['id'], array_slice($copies, 1)),
        ];
    }

    /**
     * Every entry stored more than once, as lists of its copies, by bill.
     *
     * @param int|null $sessionId Only this session's, or every session's.
     * @return array<int,list<list<array<string,mixed>>>> By bill ID.
     */
    public static function find(PDO $dbh, ?int $sessionId = null): array
    {
        $sessions = $sessionId === null
            ? $dbh->query('SELECT DISTINCT session_id FROM bills_status')->fetchAll(PDO::FETCH_COLUMN)
            : [$sessionId];
        $select = $dbh->prepare(
            'SELECT id, bill_id, session_id, status, date, translation, chamber, lis_vote_id, date_created
            FROM bills_status
            WHERE session_id = ?
            ORDER BY bill_id, id'
        );

        $entries = [];
        foreach ($sessions as $session) {
            // One session at a time, since there are hundreds of thousands of entries in all.
            $select->execute([(int) $session]);
            $byKey = [];
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byKey[(int) $row['bill_id']][self::key((string) $row['status'], (string) $row['date'])][] = $row;
            }
            foreach ($byKey as $billId => $keys) {
                foreach ($keys as $copies) {
                    if (count($copies) > 1) {
                        $entries[$billId][] = $copies;
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * Merges these entries' copies, in one transaction.
     *
     * @param array<int,list<list<array<string,mixed>>>> $entries As find() returns them.
     * @return int The number of rows removed.
     */
    public static function apply(PDO $dbh, array $entries): int
    {
        $delete = $dbh->prepare('DELETE FROM bills_status WHERE id = ?');
        $removed = 0;

        $dbh->beginTransaction();
        try {
            foreach ($entries as $copiesOfEntries) {
                foreach ($copiesOfEntries as $copies) {
                    $merge = self::merge($copies);
                    // The copies go first, since the one kept may take a copy's date, and with it
                    // the copy's place in the unique key.
                    foreach ($merge['delete'] as $id) {
                        $delete->execute([$id]);
                        $removed += $delete->rowCount();
                    }
                    if ($merge['set'] !== []) {
                        $columns = implode(', ', array_map(
                            static fn($c) => $c . ' = :' . $c,
                            array_keys($merge['set'])
                        ));
                        $dbh->prepare('UPDATE bills_status SET ' . $columns . ' WHERE id = :id')
                            ->execute($merge['set'] + ['id' => $merge['keep']]);
                    }
                }
            }
            $dbh->commit();
        } catch (\Throwable $e) {
            $dbh->rollBack();
            throw $e;
        }

        return $removed;
    }
}
