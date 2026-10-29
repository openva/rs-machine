<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/settings.inc.php';
require_once __DIR__ . '/../../../includes/functions.inc.php';
require_once __DIR__ . '/../../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../../includes/class.Database.php';

/**
 * The base for tests in the `local` suite, which check the data that rs-machine writes, using the
 * local-only sample of production's database (deploy/local-test-records.sql; see
 * deploy/build_database.sh), the same sample that richmondsunlight.com's local tests use.
 *
 * These test the data, not the code: each states something that must be true of any data that
 * the cron jobs have written, so a failure usually means a job wrote something wrong, or that
 * something in production needs cleaning up. They're skipped when the sample isn't loaded.
 *
 * This repository is public, and so are its CI logs. Failure messages may name bills,
 * legislators, and record IDs, which are public anyway, but nothing else from the sample.
 */
abstract class LocalTestCase extends TestCase
{
    /**
     * The committed test data has about 40 bills; the local sample has a few hundred.
     */
    private const LOCAL_BILL_COUNT = 150;

    /** How many offending records a failure message lists. */
    private const MAX_LISTED = 20;

    private static ?PDO $pdo = null;
    private static ?bool $localDataLoaded = null;

    protected function setUp(): void
    {
        if (self::pdo() === null) {
            $this->markTestSkipped('No database connection is available.');
        }
        if (!self::localDataLoaded()) {
            $this->markTestSkipped(
                'The local sample isn’t loaded; see deploy/build_database.sh for how to get it.'
            );
        }
    }

    protected static function pdo(): ?PDO
    {
        if (self::$pdo === null) {
            try {
                $connection = (new Database())->connect();
            } catch (Throwable $e) {
                $connection = false;
            }
            if ($connection instanceof PDO) {
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo = $connection;
            }
        }
        return self::$pdo;
    }

    private static function localDataLoaded(): bool
    {
        if (self::$localDataLoaded === null) {
            self::$localDataLoaded = (int) self::pdo()->query('SELECT COUNT(*) FROM bills')->fetchColumn()
                >= self::LOCAL_BILL_COUNT;
        }
        return self::$localDataLoaded;
    }

    /**
     * Run a query, returning all of its rows.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function rows(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assert that a query finds no offending rows, listing them if it does.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function assertNone(array $rows, string $problem): void
    {
        if ($rows === []) {
            $this->addToAssertionCount(1);
            return;
        }

        $listed = array_map(
            fn(array $row) => '  ' . implode(', ', array_map(
                fn($key, $value) => $key . '=' . ($value ?? 'NULL'),
                array_keys($row),
                $row
            )),
            array_slice($rows, 0, self::MAX_LISTED)
        );
        if (count($rows) > self::MAX_LISTED) {
            $listed[] = '  …and ' . (count($rows) - self::MAX_LISTED) . ' more';
        }

        $this->fail(count($rows) . ' ' . $problem . ":\n" . implode("\n", $listed));
    }
}
