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
     * The most characters of a bill's text to send to a model: about 25,000 tokens, which costs
     * about six cents. Some bills run to 700,000 characters, which is more than a model will accept
     * (and the call would fail every time it was tried).
     */
    public const MODEL_MAX_CHARS = 100000;

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
     * Where a bill's text shows what it changes: text that it adds is in <ins> or <em class="new">
     * (the two ways that LIS marks it), and text that it strikes is in <s>.
     */
    private const CHANGED = '/<ins(?:\s[^>]*)?>(.*?)<\/ins>'
        . '|<s(?:\s[^>]*)?>(.*?)<\/s>'
        . '|<em\s[^>]*\bclass=["\']?new\b[^>]*>(.*?)<\/em>/is';

    /**
     * Where a bill's text begins a section of the Code or an enactment clause ("2. That the
     * Department shall..."). Each begins something that's amended, added, or enacted on its own.
     */
    private const BLOCK_START = '/(?=<p\s[^>]*\bclass=["\']?(?:catchln|enactcls)\b)/i';

    /**
     * The text of a bill to look for places in: its summary, and the language that the bill
     * changes. A bill that amends a section of the Code of Virginia reprints the whole section, and
     * the places that it mentions but doesn't change aren't the places that it's about.
     *
     * @param object $bill A bill, with a "summary" and its "full_text" (as the database has them).
     */
    public static function billText(object $bill): string
    {
        $summary = html_entity_decode(strip_tags((string) ($bill->summary ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace("\u{00A0}", ' ', $summary) . ' ' . self::changedLanguage((string) ($bill->full_text ?? ''));
    }

    /**
     * The language that a bill changes: what it adds and what it strikes from existing law, and all
     * of what it enacts that isn't an amendment.
     *
     * The text is taken a section at a time. In a section that's amended, only the marked passages
     * (added or struck) are taken, so that a place that the law already names isn't taken because
     * the bill adds another one next to it. A section or enactment clause with nothing marked is
     * wholly new (a new section, a clause creating a work group, an effective date), and all of it
     * is taken. So is the text of a resolution, which has no markup at all.
     *
     * @param string $html The text of a bill, as the API returns it.
     */
    public static function changedLanguage(string $html): string
    {
        $language = [];
        foreach (preg_split(self::BLOCK_START, $html) as $block) {
            if (preg_match_all(self::CHANGED, $block, $passages, PREG_SET_ORDER) > 0) {
                foreach ($passages as $passage) {
                    $language[] = $passage[1] . ($passage[2] ?? '') . ($passage[3] ?? '');
                }
            } else {
                $language[] = $block;
            }
        }

        // Entities (a "&nbsp;" between "Fairfax" and "County") would keep a name from matching.
        $text = html_entity_decode(strip_tags(implode("\n", $language)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace("\u{00A0}", ' ', $text));
    }

    /**
     * A text, cut to what can be sent to a model (see MODEL_MAX_CHARS). Exact matching sees all of
     * the text; only the model's share is cut.
     */
    public static function forModel(string $text): string
    {
        return mb_strlen($text) <= self::MODEL_MAX_CHARS ? $text : mb_substr($text, 0, self::MODEL_MAX_CHARS);
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
