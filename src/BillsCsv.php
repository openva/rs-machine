<?php

declare(strict_types=1);

namespace RsMachine;

use DateTimeImmutable;

/**
 * Fields of LIS's BILLS.CSV that bills.php reads itself, rather than through richmondsunlight.com's
 * Import::prepare_bill(): ones it doesn't read, and ones it reads too loosely.
 */
final class BillsCsv
{
    /** The column of Introduction_date, counting from 0. */
    public const INTRODUCTION_DATE = 39;

    /** The columns of Full_text_doc1 and Full_text_doc6, each followed by its date. */
    private const FIRST_TEXT_VERSION = 22;
    private const LAST_TEXT_VERSION = 32;

    /**
     * The date a bill was introduced (or prefiled), as Y-m-d, or null when the row has none.
     *
     * @param array<int|string,mixed> $row A row of the CSV, as str_getcsv() returns it.
     */
    public static function introductionDate(array $row): ?string
    {
        return self::date((string) ($row[self::INTRODUCTION_DATE] ?? ''));
    }

    /**
     * The versions of a bill's text that the row lists, each with its date, in the order listed.
     *
     * A version is skipped unless it's named for the bill (HB397, HB397H1, HB397ER2) or is a
     * chapter (CHAP0920), and has a date. A damaged row once listed HB397's version as "H", with an
     * unreadable date, and that version was tried for months.
     *
     * @param array<int|string,mixed> $row A row of the CSV, as str_getcsv() returns it.
     * @return array<string,string> Version => its date, as Y-m-d.
     */
    public static function textVersions(array $row): array
    {
        $bill = strtoupper(trim((string) ($row[0] ?? '')));
        if (preg_match('/^[HS][BJR]\d+$/', $bill) !== 1) {
            return [];
        }

        $versions = [];
        for ($column = self::FIRST_TEXT_VERSION; $column <= self::LAST_TEXT_VERSION; $column += 2) {
            $version = strtoupper(trim((string) ($row[$column] ?? '')));
            $date = self::date((string) ($row[$column + 1] ?? ''));
            if (
                $date !== null
                && !isset($versions[$version])
                && preg_match('/^(?:' . $bill . '(?:[A-Z]+\d*)*|CHAP\d+)$/', $version) === 1
            ) {
                $versions[$version] = $date;
            }
        }

        return $versions;
    }

    /**
     * A date as LIS writes it, as Y-m-d, or null if it isn't one.
     *
     * LIS has written dates as "2023-11-20T00:00:00" (2024) and as "11/17/2025" (since 2025).
     */
    private static function date(string $value): ?string
    {
        $value = trim($value);

        // Choose the format by the value's shape: "!n/j/Y" would read "11/18/19" as the year 19.
        $format = match (1) {
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) => '!Y-m-d\TH:i:s',
            preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value) => '!n/j/Y',
            preg_match('/^\d{1,2}\/\d{1,2}\/\d{2}$/', $value) => '!n/j/y',
            default => null,
        };
        if ($format === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
