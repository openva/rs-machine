<?php

declare(strict_types=1);

namespace RsMachine;

use DateTimeImmutable;

/**
 * Fields of LIS's BILLS.CSV that richmondsunlight.com's Import::prepare_bill() doesn't read.
 */
final class BillsCsv
{
    /** The column of Introduction_date, counting from 0. */
    public const INTRODUCTION_DATE = 39;

    /**
     * The date a bill was introduced (or prefiled), as Y-m-d, or null when the row has none.
     *
     * LIS has written it as "2023-11-20T00:00:00" (2024) and as "11/17/2025" (since 2025).
     *
     * @param array<int|string,mixed> $row A row of the CSV, as str_getcsv() returns it.
     */
    public static function introductionDate(array $row): ?string
    {
        $value = trim((string) ($row[self::INTRODUCTION_DATE] ?? ''));

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
