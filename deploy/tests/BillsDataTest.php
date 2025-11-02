<?php

use PHPUnit\Framework\TestCase;

class BillsDataTest extends TestCase
{
    protected $testDataPath;
    protected $testBills;

    protected function setUp(): void
    {
        $this->testDataPath = __DIR__ . '/data/bills.csv';

        // Select specific test cases - bills with complex descriptions
        $this->testBills = [
            'HB273' => [
                'description' => 'Divorce; cruelty, reasonable apprehension of bodily hurt, or willful desertion or abandonment.',
                'patron_id' => 'H0301'
            ],
            'HB1434' => [
                'description' => 'Mattaponi Indian Tribe; DOF to convey tracts of land in Sandy Point State Forest to the Tribe.',
                'patron_id' => 'H0238'
            ],
            'HB1373' => [
                'description' => 'Roanoke Higher Education Authority; powers and duties, specialized noncredit workforce training.',
                'patron_id' => 'H0333'
            ],
            'HB1355' => [
                'description' => 'Information Technology Access Act; numerous organizational changes to Act.',
                'patron_id' => 'H0305'
            ],
            'HB1273' => [
                'description' => 'VA Public Procurement Act; additional public works contract requirements, delayed effective date.',
                'patron_id' => 'H0281'
            ]
        ];
    }

    public function testBillDataMatchesCSV()
    {
        $handle = fopen($this->testDataPath, 'r');
        $headers = fgetcsv($handle);

        // Create header index map
        $headerMap = array_flip($headers);

        // Read CSV and store test bills
        $csvBills = [];
        while (($row = fgetcsv($handle)) !== false) {
            $billId = trim($row[$headerMap['Bill_id']]);
            if (array_key_exists($billId, $this->testBills)) {
                $csvBills[$billId] = [
                    'description' => trim($row[$headerMap['Bill_description']]),
                    'patron_id' => trim($row[$headerMap['Patron_id']])
                ];
            }
        }
        fclose($handle);

        // Compare each test bill with CSV data
        foreach ($this->testBills as $billId => $expectedData) {
            $this->assertArrayHasKey($billId, $csvBills, "Bill $billId not found in CSV");
            $this->assertEquals(
                $expectedData['description'],
                $csvBills[$billId]['description'],
                "Description mismatch for $billId"
            );
            $this->assertEquals(
                $expectedData['patron_id'],
                $csvBills[$billId]['patron_id'],
                "Patron ID mismatch for $billId"
            );
        }
    }

    public function testSpecialCharacterHandling()
    {
        $handle = fopen($this->testDataPath, 'r');
        $headers = fgetcsv($handle);
        $headerMap = array_flip($headers);

        while (($row = fgetcsv($handle)) !== false) {
            $description = $row[$headerMap['Bill_description']];
            $description = rtrim($description);
            $trimmed = trim($description);

            // Test for proper handling of semicolons
            if (strpos($description, ';') !== false) {
                $this->assertNotEmpty($trimmed);
            }

            // Test for proper handling of quotes
            if (strpos($description, '"') !== false) {
                $this->assertNotEquals('"', substr($trimmed, 0, 1));
                if (substr($trimmed, -1) === '"') {
                    $this->assertGreaterThan(
                        1,
                        substr_count($trimmed, '"'),
                        'Trailing quote without matching pair.'
                    );
                }
            }

            // Test for proper handling of special characters
            $this->assertFalse(strpos($description, "\r"));
            $this->assertFalse(strpos($description, "\n"));
        }
        fclose($handle);
    }
}
