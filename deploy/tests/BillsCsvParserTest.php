<?php

use PHPUnit\Framework\TestCase;

class BillsCsvParserTest extends TestCase
{
    protected $testDataPath;

    protected function setUp(): void
    {
        $this->testDataPath = __DIR__ . '/data/bills.csv';
    }

    public function testCsvFileExists()
    {
        $this->assertFileExists($this->testDataPath);
    }

    public function testCanParseCsvFields()
    {
        $handle = fopen($this->testDataPath, 'r');
        $headers = fgetcsv($handle);
        $firstRow = fgetcsv($handle);
        fclose($handle);

        // Verify required fields exist in headers
        $requiredFields = [
            'Bill_id',
            'Bill_description',
            'Patron_id',
            'Patron_name',
            'Emergency',
            'Passed',
            'Failed',
            'Carried_over',
            'Approved',
            'Vetoed'
        ];

        foreach ($requiredFields as $field) {
            $this->assertContains($field, $headers, "Missing required field: $field");
        }

        // Test first row data integrity
        $this->assertCount(count($headers), $firstRow, "Data row should have same number of columns as headers");

        // Verify data types
        $this->assertIsString($firstRow[array_search('Bill_id', $headers)]);
        $this->assertIsString($firstRow[array_search('Bill_description', $headers)]);
        $this->assertIsString($firstRow[array_search('Patron_id', $headers)]);
        $this->assertMatchesRegularExpression('/^[YN]$/', $firstRow[array_search('Emergency', $headers)]);
        $this->assertMatchesRegularExpression('/^[YN]$/', $firstRow[array_search('Passed', $headers)]);
    }

    public function testAllRowsHaveValidFormat()
    {
        $handle = fopen($this->testDataPath, 'r');
        $headers = fgetcsv($handle);
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $this->assertCount(count($headers), $row, "Row $rowNum has incorrect number of columns");
            $this->assertNotEmpty($row[array_search('Bill_id', $headers)], "Row $rowNum missing Bill_id");
            $this->assertMatchesRegularExpression(
                '/^[YN]$/',
                $row[array_search('Emergency', $headers)],
                "Row $rowNum has invalid Emergency value"
            );
            $rowNum++;
        }
        fclose($handle);
    }
}
