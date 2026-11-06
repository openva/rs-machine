<?php

namespace RsMachine;

/**
 * Finds the places that a bill names, so that bills can be listed on those places' pages.
 *
 * Most bills that name a county or a city do it plainly ("Fairfax County," "City of Norfolk"),
 * and those can be found without asking GPT. Only bills that name places some other way ("the
 * Counties of Henrico and Chesterfield," "the Town of Vienna") are left for it.
 */
class PlaceNames
{
    /**
     * The first session year whose bills are tagged with places. This matches the first year that
     * the places pages list (Places::FIRST_YEAR, in richmondsunlight.com).
     */
    public const FIRST_YEAR = 2024;

    /**
     * Phrases that suggest a place is named in a way that exact matching can't handle: a
     * "County of," "City of," or "Town of" that isn't followed by a place we know, a list ("Counties
     * of"), or a capitalized name followed by "County" or "City" (a town's, say, or one we missed).
     */
    private const UNMATCHED = '/(?i:\b(?:Counties|Cities|City|Towns?|County)\s+of\b)'
        . '|\b(?:[A-Z][\w.\'’-]*\s+)+(?:County|City)\b/u';

    /**
     * Words in a model's answer that mean it found no places (it's asked to stay silent, but
     * doesn't always).
     */
    private const NO_PLACES = ['mentions', 'mentioned', 'provided text', 'specific'];

    /**
     * Find the counties and cities that a text names exactly, and say whether that's all of the
     * places that it names.
     *
     * A county is named as "Fairfax County" or "County of Fairfax," and a city as "City of Fairfax"
     * or "Fairfax City." Anything less exact isn't matched: a bare "Richmond" could be the city,
     * the county, or neither.
     *
     * @param string $text
     * @param array<int, array{name: string, county: bool}> $localities The counties and cities
     *     that can be found, by their names in the gazetteer ("Fairfax County," "Fairfax").
     * @return array{places: string[], settled: bool} The names of the places found, and whether the
     *     text names any other places (in which case, it isn't settled).
     */
    public static function fromText(string $text, array $localities): array
    {
        // Longest names first, and remove what's found, so that "City of Manassas Park" isn't also
        // found as "City of Manassas."
        usort($localities, fn ($a, $b) => strlen($b['name']) <=> strlen($a['name']));

        $found = [];
        foreach ($localities as $locality) {
            $pattern = self::pattern($locality);
            if (preg_match($pattern, $text) === 1) {
                $found[] = $locality['name'];
                $text = preg_replace($pattern, ' ', $text);
            }
        }

        return ['places' => $found, 'settled' => preg_match(self::UNMATCHED, $text) !== 1];
    }

    /**
     * @param array{name: string, county: bool} $locality
     */
    private static function pattern(array $locality): string
    {
        if ($locality['county']) {
            $name = self::words(preg_replace('/\s+County$/', '', $locality['name']));
            return '/\b(?:' . $name . '\s+County|County\s+of\s+' . $name . ')\b/iu';
        }
        $name = self::words($locality['name']);
        return '/\b(?:City\s+of\s+' . $name . '|' . $name . '\s+City)\b/iu';
    }

    /**
     * A name as a pattern: escaped, and matching any run of whitespace between its words.
     */
    private static function words(string $name): string
    {
        return implode('\s+', array_map(fn ($word) => preg_quote($word, '/'), preg_split('/\s+/', trim($name))));
    }

    /**
     * The text of a bill to look for places in: its summary, and the text that the bill changes
     * (or, if it doesn't change existing text, all of its text). A bill that amends a section of
     * the Code of Virginia reprints the whole section, and the places that it mentions but doesn't
     * change aren't the places that it's about.
     *
     * @param object $bill A bill, as the API returns it.
     */
    public static function billText(object $bill): string
    {
        $text = (string) ($bill->summary ?? '') . ' ';
        if (empty($bill->changes)) {
            return $text . strip_tags((string) ($bill->full_text ?? ''));
        }
        foreach ($bill->changes as $change) {
            $text .= $change->text . "\n";
        }
        return $text;
    }

    /**
     * The places in a model's answer.
     *
     * @param string $answer A list of place names, separated by commas.
     * @return string[] The names of the places, if it found any.
     */
    public static function fromAnswer(string $answer): array
    {
        $answer = trim($answer);
        if ($answer === '') {
            return [];
        }
        foreach (self::NO_PLACES as $phrase) {
            if (stripos($answer, $phrase) !== false) {
                return [];
            }
        }
        $places = array_map(fn ($place) => trim($place, " \t\n\r.\"'"), explode(',', $answer));
        return array_values(array_unique(array_filter($places, fn ($place) => $place !== '')));
    }

    /**
     * How to find a place that a model named in the gazetteer.
     *
     * @return array{name: string, county: bool}|null The gazetteer's name for the place, and
     *     whether it's a county (otherwise, it's a city or a town), or null if it isn't a place
     *     that can be told apart ("Richmond," with no "County," "City of," or "Town of").
     */
    public static function lookup(string $place): ?array
    {
        if (preg_match('/^County\s+of\s+(.+)$/i', $place, $matches) === 1) {
            return ['name' => trim($matches[1]) . ' County', 'county' => true];
        }
        if (preg_match('/\sCounty$/i', $place) === 1) {
            return ['name' => $place, 'county' => true];
        }
        if (preg_match('/^(?:City|Town)\s+of\s+(.+)$/i', $place, $matches) === 1) {
            return ['name' => trim($matches[1]), 'county' => false];
        }
        return null;
    }
}
