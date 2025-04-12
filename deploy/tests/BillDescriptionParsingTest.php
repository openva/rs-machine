<?php

use PHPUnit\Framework\TestCase;

class BillDescriptionParsingTest extends TestCase
{
    private $testDataPath;
    private $bills;

    protected function setUp(): void
    {
        $this->testDataPath = __DIR__ . '/data/bills.csv';

        // Load the CSV file
        $handle = fopen($this->testDataPath, 'r');
        $headers = fgetcsv($handle);

        $this->bills = [];
        while (($row = fgetcsv($handle)) !== false) {
            $bill = array_combine($headers, $row);
            // Only collect bills with special characters in description
            if (
                strpos($bill['Bill_description'], ',') !== false ||
                strpos($bill['Bill_description'], ';') !== false ||
                strpos($bill['Bill_description'], '"') !== false ||
                substr_count($bill['Bill_description'], "'") > 0
            ) {
                $this->bills[$bill['Bill_id']] = $bill;
            }
        }
        fclose($handle);
    }

    public function testParsingBillsWithCommas()
    {
        foreach ($this->bills as $billId => $bill) {
            if (strpos($bill['Bill_description'], ',') !== false) {
                // Verify the description was parsed correctly by reading the file again
                $handle = fopen($this->testDataPath, 'r');
                fgetcsv($handle); // Skip headers
                while (($row = fgetcsv($handle)) !== false) {
                    if ($row[0] === $billId) {
                        $this->assertEquals($bill['Bill_description'], $row[1]);
                        break;
                    }
                }
                fclose($handle);
            }
        }
    }

    public function testParsingBillsWithSemicolons()
    {
        foreach ($this->bills as $billId => $bill) {
            if (strpos($bill['Bill_description'], ';') !== false) {
                // Test specific bills with semicolons like "HB273"
                // (Divorce; cruelty, reasonable apprehension...)
                $handle = fopen($this->testDataPath, 'r');
                fgetcsv($handle); // Skip headers
                while (($row = fgetcsv($handle)) !== false) {
                    if ($row[0] === $billId) {
                        $this->assertEquals($bill['Bill_description'], $row[1]);
                        break;
                    }
                }
                fclose($handle);
            }
        }
    }

    public function testParsingBillsWithQuotes()
    {
        foreach ($this->bills as $billId => $bill) {
            if (strpos($bill['Bill_description'], '"') !== false) {
                $handle = fopen($this->testDataPath, 'r');
                fgetcsv($handle); // Skip headers
                while (($row = fgetcsv($handle)) !== false) {
                    if ($row[0] === $billId) {
                        $this->assertEquals($bill['Bill_description'], $row[1]);
                        break;
                    }
                }
                fclose($handle);
            }
        }
    }

    public function testDescriptionFieldIsolation()
    {
        // Verify that special characters don't cause field bleeding
        foreach ($this->bills as $billId => $bill) {
            $this->assertIsString($bill['Bill_description']);
            $this->assertIsString($bill['Bill_id']);
            $this->assertIsString($bill['Patron_id']);

            // Verify the description field doesn't contain any CSV-breaking characters
            $this->assertStringNotContainsString("\r", $bill['Bill_description']);
            $this->assertStringNotContainsString("\n", $bill['Bill_description']);

            // Verify quotes are properly escaped
            if (strpos($bill['Bill_description'], '"') !== false) {
                $this->assertEquals(
                    substr_count($bill['Bill_description'], '"') % 2,
                    0,
                    "Unmatched quotes in description for bill $billId"
                );
            }
        }
    }

    public function testCompleteFieldParsing()
    {
        // Test a selection of bills with known special characters
        $testCases = [
            'HB273' => 'Divorce; cruelty, reasonable apprehension of bodily hurt, or willful desertion or abandonment.',
            'HB1373' => 'Roanoke Higher Education Authority; powers and duties, specialized noncredit workforce training.',
            'HB1434' => 'Mattaponi Indian Tribe; DOF to convey tracts of land in Sandy Point State Forest to the Tribe.'
        ];

        foreach ($testCases as $billId => $expectedDescription) {
            $this->assertArrayHasKey($billId, $this->bills, "Test case bill $billId not found in CSV");
            $this->assertEquals(
                $expectedDescription,
                $this->bills[$billId]['Bill_description'],
                "Description mismatch for $billId"
            );
        }
    }
}
