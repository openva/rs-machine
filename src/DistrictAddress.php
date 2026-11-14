<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * Legislators' district office addresses (`terms.address_district`, `representatives.address_district`)
 * in the one form the website displays properly: "street, city, ST ZIP".
 *
 * The website's Legislator::get() puts a line break before the city by splitting the address on
 * its last two commas. Stored addresses have come in other forms over the years, each of which
 * breaks in the wrong place: from LIS's API, "P.O. Box 257, Keswick, VA, 22947" (a comma before the
 * ZIP code, which Import::buildAddress() added until November 2026); from older scrapers, "Suite
 * 100-B, Winchester VA 22601" (no comma before the state), "Chantilly, Virginia 20151", and
 * addresses on several lines.
 */
final class DistrictAddress
{
    private const ZIP = '\d{5}(?:-\d{4})?';

    /**
     * The address in the form "street, city, ST ZIP", or null if it's no address at all (just a
     * state, as the API gives for some offices). An address that can't be recognized is returned
     * tidied, but otherwise as it was.
     */
    public static function normalize(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        // Lines, whether real line breaks or escaped ones ("\n", "\\r") stored as text.
        $text = trim($address, " \t\"");
        $text = preg_replace('/\s*(?:\r\n|\r|\n|(?:\\\\+[rn])+)\s*/', ', ', $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $text);
        $text = preg_replace('/\s*,(?:\s*,)+\s*/', ', ', (string) $text);
        $text = trim((string) $text, ' ,');

        if ($text === '' || preg_match('/^(?:VA|Virginia)\.?$/i', $text) === 1) {
            return null;
        }

        $zip = self::ZIP;

        // "Virginia 20151" and "VA. 20175" are "VA 20151".
        $text = preg_replace('/\b(?:Virginia|VA)\.?,?\s+(' . $zip . ')$/i', 'VA $1', (string) $text);

        // "Winchester VA 22601" is "Winchester, VA 22601".
        $text = preg_replace('/([^,\s]) VA (' . $zip . ')$/', '$1, VA $2', (string) $text);

        return (string) $text;
    }
}
