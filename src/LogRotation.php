<?php

declare(strict_types=1);

namespace RsMachine;

use DateTimeImmutable;
use RuntimeException;

/**
 * Archives a log file, compressed, and deletes old archives, as logrotate would.
 *
 * logs/site.log is appended to by every task, and nothing ever trimmed it. Each rotation moves it
 * aside and compresses it as site.log-20261106.gz, in the same directory. Writers don't need to
 * be told: Log opens the file for each message, so the next message starts a new site.log.
 */
final class LogRotation
{
    /**
     * Archive a log, and delete its archives that are more than $keep_days old.
     *
     * A file left over from a rotation that was interrupted while compressing is archived first.
     *
     * @return string|null The new archive's path, or null if the log was missing or empty.
     */
    public static function rotate(string $file, int $keep_days, DateTimeImmutable $now): ?string
    {
        $pending = $file . '.rotating';
        if (!file_exists($pending)) {
            if (!is_file($file) || filesize($file) === 0) {
                self::prune($file, $keep_days, $now);
                return null;
            }
            if (!rename($file, $pending)) {
                throw new RuntimeException('Could not move ' . $file . ' aside');
            }
        }

        $archive = $file . '-' . $now->format('Ymd') . '.gz';
        if (file_exists($archive)) {
            $archive = $file . '-' . $now->format('Ymd-His') . '.gz';
        }
        self::compress($pending, $archive);
        unlink($pending);

        self::prune($file, $keep_days, $now);

        return $archive;
    }

    /**
     * Compress a file a megabyte at a time, since it may be far too large to read at once. The
     * archive appears only once it's complete.
     */
    private static function compress(string $source, string $archive): void
    {
        $partial = $archive . '.partial';
        $in = fopen($source, 'rb');
        $out = gzopen($partial, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException('Could not compress ' . $source);
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
                    throw new RuntimeException('Could not compress ' . $source);
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }

        if (!rename($partial, $archive)) {
            throw new RuntimeException('Could not write ' . $archive);
        }
    }

    /**
     * Delete a log's archives that are more than $keep_days old.
     */
    private static function prune(string $file, int $keep_days, DateTimeImmutable $now): void
    {
        $cutoff = $now->getTimestamp() - $keep_days * 86400;
        foreach (glob($file . '-*.gz') ?: [] as $archive) {
            if (filemtime($archive) < $cutoff) {
                unlink($archive);
            }
        }
    }
}
