<?php

declare(strict_types=1);

namespace RsMachine;

use PDO;

/**
 * Keeps the sessions table in step with LIS's list of sessions (the API's GetSessionListAsync),
 * for cron/tasks/sessions.php: adding each session that LIS lists and we lack, and filling in what
 * LIS knows that we don't, such as the date a session adjourned.
 *
 * Each LIS session has a code, "20271": the year, and 1 for the regular session or 2, 3, … for
 * its special sessions. sessions.lis_id is the code without its leading "20" ("271"), and
 * sessions.suffix is a special session's number ("1" for Special Session I), or null for a
 * regular session. LIS's events are matched by their names ("Session Start"), since their types
 * are inconsistent (2026's Adjournment has the type of a Closing).
 *
 * The website reads which session is current from its settings, not from this table. Its
 * session-update workflow proposes the change, using the IDs in downloads/sessions.json, which the
 * task writes from this table.
 */
final class Sessions
{
    /** The columns published in sessions.json. */
    public const PUBLISHED = [
        'id', 'lis_id', 'lis_api_id', 'year', 'suffix', 'date_started', 'date_ended', 'crossover',
    ];

    /**
     * A session from GetSessionListAsync, as a row of sessions (without its ID), or null if it
     * can't be one: a code we can't read, or no start date yet.
     *
     * @param array<string,mixed> $session
     * @return array{lis_id:string,lis_api_id:int,year:int,suffix:?string,date_started:string,
     *               date_ended:?string,crossover:?string,active:bool}|null
     */
    public static function fromLis(array $session): ?array
    {
        $code = (string) ($session['SessionCode'] ?? '');
        if (preg_match('/^(\d{4})(\d)$/', $code, $match) !== 1 || !isset($session['SessionID'])) {
            return null;
        }
        $number = (int) $match[2];
        $regular = ($session['SessionType'] ?? '') === 'Regular';
        if ($number < 1 || ($regular && $number !== 1) || (!$regular && $number === 1)) {
            return null;
        }

        $events = [];
        foreach ($session['SessionEvents'] ?? [] as $event) {
            $date = self::date($event['ActualDate'] ?? null);
            if (isset($event['DisplayName']) && $date !== null) {
                $events[(string) $event['DisplayName']] = $date;
            }
        }
        if (!isset($events['Session Start'])) {
            return null;
        }

        return [
            'lis_id'       => substr($code, 2),
            'lis_api_id'   => (int) $session['SessionID'],
            'year'         => (int) $match[1],
            'suffix'       => $regular ? null : (string) ($number - 1),
            'date_started' => $events['Session Start'],
            'date_ended'   => $events['Adjournment'] ?? null,
            'crossover'    => $events['Crossover'] ?? null,
            'active'       => ($session['IsActive'] ?? false) === true,
        ];
    }

    /**
     * What must change for the stored sessions to agree with LIS's: the sessions to add, and the
     * columns to set on those we have, by ID.
     *
     * Only sessions that begin after the latest we have are added. LIS lists every session since
     * 1994, and we have none before 2006, nor some special sessions since, by choice.
     *
     * What we have is kept unless it's missing, since some sessions' dates were recorded on
     * purpose differently than LIS has them. The exception is the adjournment of a session that
     * LIS still lists as active, which may have been entered before it happened, as a projection.
     *
     * @param list<array<string,mixed>> $lis    LIS's sessions, as fromLis() returns them.
     * @param list<array<string,mixed>> $stored Rows of sessions.
     * @return array{insert:list<array<string,mixed>>,update:array<int,array<string,mixed>>}
     */
    public static function changes(array $lis, array $stored): array
    {
        $byLisId = [];
        $latest = '';
        foreach ($stored as $row) {
            $byLisId[(string) $row['lis_id']] = $row;
            $latest = max($latest, (string) self::date($row['date_started'] ?? null));
        }

        $insert = [];
        $update = [];
        foreach ($lis as $session) {
            $row = $byLisId[$session['lis_id']] ?? null;
            if ($row === null) {
                if ($session['date_started'] > $latest) {
                    $insert[] = array_diff_key($session, ['active' => true]);
                }
                continue;
            }

            $set = [];
            if (self::isEmpty($row['lis_api_id'] ?? null)) {
                $set['lis_api_id'] = $session['lis_api_id'];
            }
            $ended = self::date($row['date_ended'] ?? null);
            if (
                $session['date_ended'] !== null && $session['date_ended'] !== $ended
                && ($ended === null || $session['active'])
            ) {
                $set['date_ended'] = $session['date_ended'];
            }
            if ($session['crossover'] !== null && self::date($row['crossover'] ?? null) === null) {
                $set['crossover'] = $session['crossover'];
            }
            if ($set !== []) {
                $update[(int) $row['id']] = $set;
            }
        }

        // Oldest first, so that IDs are in order of the sessions.
        usort($insert, static fn($a, $b) => [$a['date_started'], $a['lis_id']]
            <=> [$b['date_started'], $b['lis_id']]);

        return ['insert' => $insert, 'update' => $update];
    }

    /**
     * Makes the sessions table agree with LIS's sessions.
     *
     * @param list<array<string,mixed>> $lisSessions Sessions from GetSessionListAsync, as LIS
     *                                               returned them.
     * @return array{insert:list<array<string,mixed>>,update:array<int,array<string,mixed>>}
     *         What was changed, as changes() returns it, each added session with its new ID.
     */
    public static function apply(PDO $dbh, array $lisSessions): array
    {
        $lis = array_values(array_filter(array_map(
            static fn($session) => is_array($session) ? self::fromLis($session) : null,
            $lisSessions
        )));
        $stored = $dbh->query('SELECT id, lis_id, lis_api_id, date_started, date_ended, crossover
                FROM sessions')
            ->fetchAll(PDO::FETCH_ASSOC);
        $changes = self::changes($lis, $stored);

        $dbh->beginTransaction();
        try {
            $insert = $dbh->prepare(
                'INSERT INTO sessions
                    (lis_id, lis_api_id, year, suffix, date_started, date_ended, crossover, notes,
                    date_created)
                VALUES
                    (:lis_id, :lis_api_id, :year, :suffix, :date_started, :date_ended, :crossover,
                    "", NOW())'
            );
            foreach ($changes['insert'] as $i => $session) {
                $insert->execute($session);
                $changes['insert'][$i] = ['id' => (int) $dbh->lastInsertId()] + $session;
            }

            foreach ($changes['update'] as $id => $set) {
                $columns = implode(', ', array_map(static fn($c) => $c . ' = :' . $c, array_keys($set)));
                $dbh->prepare('UPDATE sessions SET ' . $columns . ' WHERE id = :id')
                    ->execute($set + ['id' => $id]);
            }
            $dbh->commit();
        } catch (\Throwable $e) {
            $dbh->rollBack();
            throw $e;
        }

        return $changes;
    }

    /**
     * Every session, as published in sessions.json, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function published(PDO $dbh): array
    {
        $rows = $dbh->query('SELECT ' . implode(', ', self::PUBLISHED) . ' FROM sessions ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn($row) => [
            'id'           => (int) $row['id'],
            'lis_id'       => $row['lis_id'],
            'lis_api_id'   => self::isEmpty($row['lis_api_id']) ? null : (int) $row['lis_api_id'],
            'year'         => (int) $row['year'],
            'suffix'       => self::isEmpty($row['suffix']) ? null : (string) $row['suffix'],
            'date_started' => self::date($row['date_started']),
            'date_ended'   => self::date($row['date_ended']),
            'crossover'    => self::date($row['crossover']),
        ], $rows);
    }

    /**
     * How a session is named in messages: "2027 Regular Session", "2026 Special Session I".
     *
     * @param array<string,mixed> $session
     */
    public static function name(array $session): string
    {
        if (self::isEmpty($session['suffix'] ?? null)) {
            return $session['year'] . ' Regular Session';
        }
        $numerals = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];

        return $session['year'] . ' Special Session '
            . ($numerals[(int) $session['suffix']] ?? $session['suffix']);
    }

    /** "2026-03-14T00:00:00" as "2026-03-14"; null for nothing, or MySQL's zero date. */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) !== 1) {
            return null;
        }

        return $match[1] === '0000-00-00' ? null : $match[1];
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === '0';
    }
}
