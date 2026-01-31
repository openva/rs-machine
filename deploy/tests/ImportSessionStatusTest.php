<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/settings.inc.php';
require_once __DIR__ . '/../../includes/functions.inc.php';
require_once __DIR__ . '/../../includes/vendor/autoload.php';
require_once __DIR__ . '/../../includes/class.Import.php';
require_once __DIR__ . '/../../includes/class.Log.php';

if (!class_exists('NullLogSession', false)) {
    /**
     * Silent logger for test isolation.
     */
    class NullLogSession extends Log
    {
        public function put($message, $level = 3)
        {
            return true;
        }

        public function filesystem($message)
        {
            return true;
        }
    }
}

if (!class_exists('StubImportSession', false)) {
    /**
     * Import stub that returns canned LIS responses.
     */
    class StubImportSession extends Import
    {
        /** @var array */
        private $response;

        public function __construct(Log $log, array $response)
        {
            parent::__construct($log);
            $this->response = $response;
        }

        protected function lis_api_request($path, array $query = [])
        {
            // Assert that session code normalization has been applied upstream.
            if (isset($query['sessionCode'])) {
                if ($query['sessionCode'] === '123') {
                    throw new RuntimeException('Session code should have been prefixed to four digits.');
                }
            }

            return $this->response;
        }
    }
}

class ImportSessionStatusTest extends TestCase
{
    public function testGetLegislationSessionStatusesNormalizesAndFilters(): void
    {
        $response = [
            'Legislations' => [
                [
                    'LegislationID' => 101,
                    'LegislationNumber' => 'HB1',
                    'LegislationStatus' => 'Introduced',
                ],
                [
                    'LegislationID' => 102,
                    'LegislationNumber' => 'SB2',
                    'LegislationStatus' => 'In Committee',
                ],
                // Missing ID should be ignored.
                [
                    'LegislationNumber' => 'HB3',
                    'LegislationStatus' => 'Failed',
                ],
                // Non-string status should become null.
                [
                    'LegislationID' => 103,
                    'LegislationNumber' => 'HB4',
                    'LegislationStatus' => ['array'],
                ],
            ],
        ];

        $import = new StubImportSession(new NullLogSession(), $response);

        $list = $import->get_legislation_session_statuses('20261');
        $this->assertCount(3, $list);

        $this->assertSame(
            [
                'legislation_id' => 101,
                'number' => 'HB1',
                'status' => 'Introduced',
            ],
            $list[0]
        );

        $this->assertSame(
            [
                'legislation_id' => 103,
                'number' => 'HB4',
                'status' => null,
            ],
            $list[2]
        );
    }

    public function testGetLegislationSessionStatusesPrefixesThreeDigitSessionCode(): void
    {
        $response = [
            'Legislations' => [
                ['LegislationID' => 201, 'LegislationNumber' => 'HB10', 'LegislationStatus' => 'Introduced'],
            ],
        ];

        $import = new StubImportSession(new NullLogSession(), $response);

        $list = $import->get_legislation_session_statuses('261');
        $this->assertCount(1, $list);
        $this->assertSame(201, $list[0]['legislation_id']);
    }
}
