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

/**
 * Import stub that serves a single member payload for deactivation checks.
 */
class ImportDeactivationStub extends Import
{
    /** @var array<string,mixed> */
    private $memberPayload;

    public function __construct(Log $log, array $memberPayload)
    {
        parent::__construct($log);
        $this->memberPayload = $memberPayload;
    }

    protected function lis_api_request($path, array $query = [])
    {
        if ($path === '/Member/api/getmembersasync') {
            return $this->memberPayload;
        }
        if ($path === '/Member/api/getactivemembersasync') {
            return $this->memberPayload;
        }
        return [];
    }
}

class LegislatorDeactivationTest extends TestCase
{
    /**
     * Active member with no end date stays in office.
     */
    public function testLegislatorInLisReturnsTrueWhenActive(): void
    {
        $payload = [
            'Members' => [
                [
                    'MemberNumber' => 'S0085',
                    'MemberStatus' => 'Active',
                    'ServiceEndDate' => null,
                ],
            ],
            'Success' => true,
        ];

        $import = new ImportDeactivationStub(new NullLog(), $payload);
        $this->assertTrue($import->legislator_in_lis('S0085'));
    }

    /**
     * Explicit inactive status forces deactivation.
     */
    public function testLegislatorInLisReturnsFalseWhenInactive(): void
    {
        $payload = [
            'Members' => [
                [
                    'MemberNumber' => 'S0085',
                    'MemberStatus' => 'Inactive',
                    'ServiceEndDate' => null,
                ],
            ],
            'Success' => true,
        ];

        $import = new ImportDeactivationStub(new NullLog(), $payload);
        $this->assertFalse($import->legislator_in_lis('S0085'));
    }

    /**
     * End dates in the past force deactivation.
     */
    public function testLegislatorInLisReturnsFalseWhenEndDatePassed(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day')) . 'T00:00:00';
        $payload = [
            'Members' => [
                [
                    'MemberNumber' => 'S0085',
                    'MemberStatus' => 'Active',
                    'ServiceEndDate' => $yesterday,
                ],
            ],
            'Success' => true,
        ];

        $import = new ImportDeactivationStub(new NullLog(), $payload);
        $this->assertFalse($import->legislator_in_lis('S0085'));
    }

    /**
     * Future end dates keep member active.
     */
    public function testLegislatorInLisReturnsTrueWhenEndDateFuture(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day')) . 'T00:00:00';
        $payload = [
            'Members' => [
                [
                    'MemberNumber' => 'S0085',
                    'MemberStatus' => 'Active',
                    'ServiceEndDate' => $tomorrow,
                ],
            ],
            'Success' => true,
        ];

        $import = new ImportDeactivationStub(new NullLog(), $payload);
        $this->assertTrue($import->legislator_in_lis('S0085'));
    }
}
