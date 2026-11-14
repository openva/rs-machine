<?php

use PHPUnit\Framework\TestCase;
use RsMachine\DistrictAddress;

/**
 * Legislators' district office addresses, in the form the website breaks before the city.
 *
 * The cases are addresses stored in `terms`.
 */
class DistrictAddressTest extends TestCase
{
    /**
     * @dataProvider addresses
     */
    public function testAddressesAreNormalized(?string $stored, ?string $expected): void
    {
        $this->assertSame($expected, DistrictAddress::normalize($stored));
    }

    public static function addresses(): array
    {
        return [
            'a comma before the ZIP code' => [
                'P.O. Box 257, Keswick, VA, 22947', 'P.O. Box 257, Keswick, VA 22947'],
            'a comma within a street line' => [
                '732 Eden Way North, Suite E,#183, Chesapeake, VA, 23320',
                '732 Eden Way North, Suite E,#183, Chesapeake, VA 23320'],
            'already right' => [
                '123 Main Street, Midlothian, VA 23113', '123 Main Street, Midlothian, VA 23113'],
            'a ZIP+4' => [
                'PO Box 1, Richmond, VA, 23218-0001', 'PO Box 1, Richmond, VA 23218-0001'],
            'no comma before the state' => [
                '117 East Picadilly Street, Suite 100-B, Winchester VA 22601',
                '117 East Picadilly Street, Suite 100-B, Winchester, VA 22601'],
            'a city named Virginia' => [
                '4705 Columbus Street, Suite 301, Virginia Beach VA 23462',
                '4705 Columbus Street, Suite 301, Virginia Beach, VA 23462'],
            'the state spelled out' => [
                '14504 Lee Road, Unit C, Chantilly, Virginia 20151',
                '14504 Lee Road, Unit C, Chantilly, VA 20151'],
            'the state abbreviated with a period' => [
                '21029 Rodney Ln, Leesburg, VA. 20175', '21029 Rodney Ln, Leesburg, VA 20175'],
            'two spaces before the ZIP code' => [
                'PO Box 5429, Virginia Beach, VA  23471', 'PO Box 5429, Virginia Beach, VA 23471'],
            'line breaks' => [
                "10408 Courthouse Road #66\nSpotsylvania VA 22553",
                '10408 Courthouse Road #66, Spotsylvania, VA 22553'],
            'escaped line breaks, in quotation marks' => [
                '"4540 Princess Anne Road\nSuite #114\nVirginia Beach, VA 23462"',
                '4540 Princess Anne Road, Suite #114, Virginia Beach, VA 23462'],
            'escaped line breaks with spaces' => [
                'P.O. Box 5113 \nWoodbridge, Virginia 22194', 'P.O. Box 5113, Woodbridge, VA 22194'],
            'doubly escaped carriage returns' => [
                '40 British Woods Drive\\\\rSuite 101\\\\rRoanoke VA 24019',
                '40 British Woods Drive, Suite 101, Roanoke, VA 24019'],
            'only the state' => ['VA', null],
            'empty' => ['', null],
            'null' => [null, null],
            'unrecognized, left alone' => ['Somewhere in Henrico', 'Somewhere in Henrico'],
        ];
    }

    /**
     * Normalizing a normalized address changes nothing, so the utility is safe to rerun.
     */
    public function testNormalizingTwiceChangesNothing(): void
    {
        foreach (self::addresses() as [$stored]) {
            $once = DistrictAddress::normalize($stored);
            $this->assertSame($once, DistrictAddress::normalize($once));
        }
    }
}
