<?php

use PHPUnit\Framework\TestCase;

class BillsParserTest extends TestCase
{
    private $testDataPath;
    private $headers;
    private $sampleRow;

    protected function setUp(): void
    {
        $this->testDataPath = __DIR__ . '/data/bills.csv';

        // Load test data
        $handle = fopen($this->testDataPath, 'r');
        $this->headers = fgetcsv($handle);
        $this->sampleRow = fgetcsv($handle);
        fclose($handle);
    }

    public function testBillIdParsing()
    {
        $billIdIndex = array_search('Bill_id', $this->headers);
        $this->assertIsInt($billIdIndex, 'Bill_id column not found');

        // Bill ID should be in format 'HB123' or similar
        $this->assertMatchesRegularExpression(
            '/^[HS]B\d+$/',
            $this->sampleRow[$billIdIndex],
            'Bill ID format invalid'
        );
    }

    public function testEmergencyFlagParsing()
    {
        $emergencyIndex = array_search('Emergency', $this->headers);
        $this->assertIsInt($emergencyIndex, 'Emergency column not found');

        // Emergency flag should be Y or N
        $this->assertMatchesRegularExpression(
            '/^[YN]$/',
            $this->sampleRow[$emergencyIndex],
            'Emergency flag should be Y or N'
        );
    }

    public function testStatusFlagsParsing()
    {
        $statusFlags = ['Passed', 'Failed', 'Carried_over', 'Approved', 'Vetoed'];

        foreach ($statusFlags as $flag) {
            $index = array_search($flag, $this->headers);
            $this->assertIsInt($index, "$flag column not found");

            // Each status flag should be Y or N
            $this->assertMatchesRegularExpression(
                '/^[YN]$/',
                $this->sampleRow[$index],
                "$flag should be Y or N"
            );
        }
    }

    public function testDateFieldParsing()
    {
        $dateFields = [
            'Last_house_action_date',
            'Last_senate_action_date',
            'Last_conference_action_date',
            'Last_governor_action_date',
            'Full_text_date1',
            'Full_text_date2',
            'Full_text_date3',
            'Full_text_date4',
            'Full_text_date5',
            'Full_text_date6',
            'Introduction_date'
        ];

        foreach ($dateFields as $field) {
            $index = array_search($field, $this->headers);
            $this->assertIsInt($index, "$field column not found");

            if (!empty($this->sampleRow[$index])) {
                // Date should be in format MM/DD/YYYY
                $date = \DateTime::createFromFormat('m/d/Y', $this->sampleRow[$index]);
                $this->assertInstanceOf(
                    \DateTime::class,
                    $date,
                    "Date in $field is not in expected MM/DD/YYYY format"
                );
            }
        }
    }

    public function testCommitteeIdParsing()
    {
        $committeeFields = [
            'Last_house_committee_id',
            'Last_senate_committee_id'
        ];

        foreach ($committeeFields as $field) {
            $index = array_search($field, $this->headers);
            $this->assertIsInt($index, "$field column not found");

            if (!empty($this->sampleRow[$index])) {
                // Committee IDs should be H## or S##
                $this->assertMatchesRegularExpression(
                    '/^[HS]\d{2}$/',
                    $this->sampleRow[$index],
                    "Committee ID in $field is not in expected format"
                );
            }
        }
    }

    public function testActionIdParsing()
    {
        $actionFields = [
            'Last_house_actid',
            'Last_senate_actid',
            'Last_conference_actid',
            'Last_governor_actid',
            'Last_actid'
        ];

        foreach ($actionFields as $field) {
            $index = array_search($field, $this->headers);
            $this->assertIsInt($index, "$field column not found");

            if (!empty($this->sampleRow[$index])) {
                // Action IDs should be H#### or S####
                $this->assertMatchesRegularExpression(
                    '/^[HS]\d{4}$/',
                    $this->sampleRow[$index],
                    "Action ID in $field is not in expected format"
                );
            }
        }
    }
}
