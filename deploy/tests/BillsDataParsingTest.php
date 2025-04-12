<?php

use PHPUnit\Framework\TestCase;

class BillsDataParsingTest extends TestCase
{
    protected $testDataPath;
    protected $csvData;

    protected function setUp(): void
    {
        $this->testDataPath = __DIR__ . '/data/test_bills.csv';

        // Load the CSV data
        $handle = fopen($this->testDataPath, 'r');
        $headers = fgetcsv($handle);

        $this->csvData = [];
        while (($row = fgetcsv($handle)) !== false) {
            $billData = array_combine($headers, $row);
            $this->csvData[$billData['Bill_id']] = $billData;
        }
        fclose($handle);
    }

    public function testSpecialCharacterHandling()
    {
        // Test comma handling
        $this->assertEquals(
            'Test bill with, comma',
            $this->csvData['HB1234']['Bill_description'],
            'Failed to properly parse description with comma'
        );

        // Test semicolon handling
        $this->assertEquals(
            'Test bill with; semicolon',
            $this->csvData['HB5678']['Bill_description'],
            'Failed to properly parse description with semicolon'
        );

        // Test quote handling
        $this->assertEquals(
            'Test bill with "quoted text"',
            $this->csvData['HB9012']['Bill_description'],
            'Failed to properly parse description with quotes'
        );

        // Test special characters
        $this->assertEquals(
            'Test bill with special chars: #$%&\'*+',
            $this->csvData['HB3456']['Bill_description'],
            'Failed to properly parse description with special characters'
        );
    }

    public function testFieldIntegrity()
    {
        foreach ($this->csvData as $billId => $bill) {
            // Verify all required fields are present
            $this->assertArrayHasKey('Bill_id', $bill);
            $this->assertArrayHasKey('Bill_description', $bill);
            $this->assertArrayHasKey('Patron_id', $bill);
            $this->assertArrayHasKey('Emergency', $bill);

            // Verify field formats
            $this->assertMatchesRegularExpression('/^HB\d+$/', $bill['Bill_id']);
            $this->assertMatchesRegularExpression('/^H\d{4}$/', $bill['Patron_id']);
            $this->assertMatchesRegularExpression('/^[YN]$/', $bill['Emergency']);
        }
    }

    public function testLongDescriptionHandling()
    {
        $longDescription = $this->csvData['HB7890']['Bill_description'];
        $this->assertNotEmpty($longDescription);
        $this->assertTrue(
            strlen($longDescription) > 100,
            'Long description was truncated'
        );
    }

    public function testDataConsistency()
    {
        foreach ($this->csvData as $billId => $bill) {
            if ($bill['Passed'] === 'Y') {
                $this->assertEquals('N', $bill['Failed']);
                $this->assertEquals('N', $bill['Carried_over']);
            }

            if ($bill['Failed'] === 'Y') {
                $this->assertEquals('N', $bill['Passed']);
                $this->assertEquals('N', $bill['Carried_over']);
            }
        }
    }
}
