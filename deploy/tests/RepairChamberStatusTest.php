<?php

use PHPUnit\Framework\TestCase;

/**
 * Test the chamber repair script logic.
 */
class RepairChamberStatusTest extends TestCase
{
    /**
     * Test that chamber transitions work correctly for a house bill.
     */
    public function testHouseBillChamberTransitions(): void
    {
        // Simulate HB1 status history
        $statuses = [
            ['translation' => 'introduced', 'expected_chamber' => 'house'],
            ['translation' => 'in committee', 'expected_chamber' => 'house'],
            ['translation' => 'passed committee', 'expected_chamber' => 'house'],
            ['translation' => 'passed house', 'expected_chamber' => 'house'],
            ['translation' => 'in committee', 'expected_chamber' => 'senate'],  // After passage
            ['translation' => 'passed senate', 'expected_chamber' => 'senate'],
            ['translation' => 'enrolled', 'expected_chamber' => 'house'],  // Back to house
            ['translation' => 'signed by governor', 'expected_chamber' => 'house'],
        ];

        $billNumber = 'hb1';
        $originatingChamber = 'house';
        $currentChamber = $originatingChamber;

        foreach ($statuses as $status) {
            $translation = strtolower($status['translation']);

            // Check if this status indicates a chamber passage
            if (strpos($translation, 'passed house') !== false) {
                $chamberForThisStatus = 'house';
                $currentChamber = 'senate';
            } elseif (strpos($translation, 'passed senate') !== false) {
                $chamberForThisStatus = 'senate';
                $currentChamber = 'house';
            } else {
                $chamberForThisStatus = $currentChamber;
            }

            $this->assertSame(
                $status['expected_chamber'],
                $chamberForThisStatus,
                "Status '{$status['translation']}' should be in chamber '{$status['expected_chamber']}'"
            );
        }
    }

    /**
     * Test that chamber transitions work correctly for a senate bill.
     */
    public function testSenateBillChamberTransitions(): void
    {
        // Simulate SB100 status history
        $statuses = [
            ['translation' => 'introduced', 'expected_chamber' => 'senate'],
            ['translation' => 'in committee', 'expected_chamber' => 'senate'],
            ['translation' => 'passed senate', 'expected_chamber' => 'senate'],
            ['translation' => 'in committee', 'expected_chamber' => 'house'],  // After passage
            ['translation' => 'passed house', 'expected_chamber' => 'house'],
            ['translation' => 'enrolled', 'expected_chamber' => 'senate'],  // Back to senate
        ];

        $billNumber = 'sb100';
        $originatingChamber = 'senate';
        $currentChamber = $originatingChamber;

        foreach ($statuses as $status) {
            $translation = strtolower($status['translation']);

            // Check if this status indicates a chamber passage
            if (strpos($translation, 'passed house') !== false) {
                $chamberForThisStatus = 'house';
                $currentChamber = 'senate';
            } elseif (strpos($translation, 'passed senate') !== false) {
                $chamberForThisStatus = 'senate';
                $currentChamber = 'house';
            } else {
                $chamberForThisStatus = $currentChamber;
            }

            $this->assertSame(
                $status['expected_chamber'],
                $chamberForThisStatus,
                "Status '{$status['translation']}' should be in chamber '{$status['expected_chamber']}'"
            );
        }
    }

    /**
     * Test that bills that never cross over stay in originating chamber.
     */
    public function testBillThatNeverCrossesOver(): void
    {
        $statuses = [
            ['translation' => 'introduced', 'expected_chamber' => 'house'],
            ['translation' => 'in committee', 'expected_chamber' => 'house'],
            ['translation' => 'failed committee', 'expected_chamber' => 'house'],
        ];

        $billNumber = 'hb500';
        $originatingChamber = 'house';
        $currentChamber = $originatingChamber;

        foreach ($statuses as $status) {
            $translation = strtolower($status['translation']);

            // Check if this status indicates a chamber passage
            if (strpos($translation, 'passed house') !== false) {
                $chamberForThisStatus = 'house';
                $currentChamber = 'senate';
            } elseif (strpos($translation, 'passed senate') !== false) {
                $chamberForThisStatus = 'senate';
                $currentChamber = 'house';
            } else {
                $chamberForThisStatus = $currentChamber;
            }

            $this->assertSame(
                $status['expected_chamber'],
                $chamberForThisStatus,
                "Status '{$status['translation']}' should be in chamber '{$status['expected_chamber']}'"
            );
        }
    }

    /**
     * Test multiple chamber crossovers (bill gets amended and sent back).
     */
    public function testMultipleChamberCrossovers(): void
    {
        $statuses = [
            ['translation' => 'introduced', 'expected_chamber' => 'house'],
            ['translation' => 'passed house', 'expected_chamber' => 'house'],
            ['translation' => 'in committee', 'expected_chamber' => 'senate'],
            ['translation' => 'passed senate with amendments', 'expected_chamber' => 'senate'],
            ['translation' => 'house concurs with amendments', 'expected_chamber' => 'house'],
            ['translation' => 'enrolled', 'expected_chamber' => 'house'],
        ];

        $billNumber = 'hb200';
        $originatingChamber = 'house';
        $currentChamber = $originatingChamber;

        foreach ($statuses as $status) {
            $translation = strtolower($status['translation']);

            // Check if this status indicates a chamber passage
            if (strpos($translation, 'passed house') !== false) {
                $chamberForThisStatus = 'house';
                $currentChamber = 'senate';
            } elseif (strpos($translation, 'passed senate') !== false) {
                $chamberForThisStatus = 'senate';
                $currentChamber = 'house';
            } else {
                $chamberForThisStatus = $currentChamber;
            }

            $this->assertSame(
                $status['expected_chamber'],
                $chamberForThisStatus,
                "Status '{$status['translation']}' should be in chamber '{$status['expected_chamber']}'"
            );
        }
    }
}
