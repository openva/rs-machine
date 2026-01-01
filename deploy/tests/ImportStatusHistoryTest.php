<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

if (!class_exists('NullLog', false)) {
    /**
     * Silent logger for test isolation.
     */
    class NullLog extends Log
    {
        public function put($message, $level)
        {
            return true;
        }

        public function filesystem($message)
        {
            return true;
        }
    }
}

if (!class_exists('RecordingPdo', false)) {
    /**
     * In-memory PDO stand-in that records prepared SQL and bound values.
     */
    class RecordingPdo extends PDO
    {
        /** @var RecordingStatement|null */
        public $statement;

        /** @var string|null */
        public $preparedSql;

        public function __construct()
        {
        }

        public function prepare(string $statement, array $options = []): PDOStatement|false
        {
            $this->preparedSql = $statement;
            $this->statement = new RecordingStatement();
            return $this->statement;
        }
    }

    /**
     * PDOStatement stub that tracks executions for assertions.
     */
    class RecordingStatement extends PDOStatement
    {
        /** @var array<int,array<string,mixed>> */
        public $executions = [];

        /** @var array<string,mixed> */
        private $values = [];

        public function __construct()
        {
        }

        public function bindValue(string|int $param, $value, int $type = PDO::PARAM_STR): bool
        {
            $this->values[$param] = $value;
            return true;
        }

        public function execute(?array $params = null): bool
        {
            $this->executions[] = $this->values;
            return true;
        }

        public function rowCount(): int
        {
            return 1;
        }
    }
}

class ImportStatusHistoryTest extends TestCase
{
    public function testNormalizeStatusHistoryTransformsFields(): void
    {
        $fixturePath = __DIR__ . '/data/lis_api/legislation_event_history_sample.json';
        $raw = json_decode(file_get_contents($fixturePath), true);

        $this->assertIsArray($raw, 'Fixture should decode to array.');
        $this->assertArrayHasKey('LegislationEvents', $raw, 'Fixture must contain LegislationEvents.');

        $import = new Import(new NullLog());
        $normalized = $import->normalize_status_history($raw['LegislationEvents']);

        $this->assertCount(3, $normalized, 'Expected three normalized events.');

        $this->assertSame(
            [
                'chamber' => 'senate',
                'date' => '2025-11-17 14:24:00',
                'status' => 'Prefiled and ordered printed; Offered 01-14-2026 26102073D',
            ],
            $normalized[0]
        );

        $this->assertSame(
            [
                'chamber' => 'senate',
                'date' => '2025-11-17 14:24:00',
                'status' => 'Referred to Committee on Commerce and Labor',
            ],
            $normalized[1]
        );

        $this->assertSame(
            [
                'chamber' => 'house',
                'date' => '2025-12-01 09:00:00',
                'status' => 'Reported from Committee',
            ],
            $normalized[2]
        );
    }

    public function testStoreStatusHistoryPersistsNormalizedRows(): void
    {
        $import = new Import(new NullLog());
        $pdo = new RecordingPdo();

        $reflector = new ReflectionProperty(Import::class, 'pdo');
        $reflector->setAccessible(true);
        $reflector->setValue($import, $pdo);

        $history = [
            ['status' => 'Prefiled and ordered printed', 'date' => '2025-11-17T14:24:00'],
            // Duplicate should be ignored.
            ['status' => 'Prefiled and ordered printed', 'date' => '2025-11-17T14:24:00'],
            ['status' => 'Reported from Committee', 'date' => '2025-12-01T09:00:00', 'lis_vote_id' => '1234'],
            // Invalid entries skipped.
            ['status' => '', 'date' => '2025-12-02T09:00:00'],
            ['status' => 'Missing date'],
        ];

        $persisted = $import->store_status_history(42, $history, 30);

        $this->assertSame(2, $persisted, 'Expected only valid, unique rows to be persisted.');
        $this->assertNotNull($pdo->statement, 'Statement should have been prepared.');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $pdo->preparedSql);
        $this->assertCount(2, $pdo->statement->executions, 'Should execute once per unique status.');

        $first = $pdo->statement->executions[0];
        $this->assertSame(42, $first[':bill_id']);
        $this->assertSame(30, $first[':session_id']);
        $this->assertSame('Prefiled and ordered printed', $first[':status']);
        $this->assertSame('2025-11-17 14:24:00', $first[':date']);
        $this->assertNull($first[':lis_vote_id']);
        $this->assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $first[':date_created']);

        $second = $pdo->statement->executions[1];
        $this->assertSame('Reported from Committee', $second[':status']);
        $this->assertSame('2025-12-01 09:00:00', $second[':date']);
        $this->assertSame('1234', $second[':lis_vote_id']);
    }
}
