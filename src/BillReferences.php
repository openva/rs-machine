<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * The bills that a news article mentions, for cron/articles.php: by number ("HB 42," "SB42"), by
 * name ("House Bill 42"), or by a link to the bill.
 *
 * An article's HTML is searched, links and all, so a bill that's only linked to is found. Links to
 * a bill on LIS (lis.virginia.gov/bill-details/20261/HB42) and on Richmond Sunlight
 * (richmondsunlight.com/bill/2026/hb42/) say which session's bill it is, and only the current
 * session's are counted. Articles often link to past sessions' bills for background, and those
 * would otherwise be mistaken for this session's bills of the same number. (Other links, and bills
 * named in the text, can't say which session they mean, so they're taken to mean this one.)
 */
final class BillReferences
{
    /** Bills' names, and their numbers' prefixes. */
    private const TYPES = [
        'house bill' => 'hb',
        'senate bill' => 'sb',
        'house joint resolution' => 'hj',
        'senate joint resolution' => 'sj',
        'house resolution' => 'hr',
        'senate resolution' => 'sr',
    ];

    /** The rest of a URL, after the part that identifies a bill. */
    private const REST_OF_URL = '[^\s"\'<>]*';

    /**
     * Find the bills that a text mentions.
     *
     * @param string $text An article's title, description, and content (HTML is fine)
     * @param string $session_lis_id The current session's LIS ID (SESSION_LIS_ID, e.g. "261")
     * @param int $session_year The current session's year (SESSION_YEAR)
     * @return string[] Bill numbers (e.g. "hb42"), sorted
     */
    public static function find(string $text, string $session_lis_id, int $session_year): array
    {
        $found = [];

        // Links to bills, which say which session's bill they are. Each is taken out of the text,
        // so that a link to another session's bill isn't found again by its number.
        $links = [
            // LIS names a session with its year and its number: 20261 is 2026's regular session.
            '~(?:https?:)?//(?:www\.)?lis\.virginia\.gov/bill-details/(\d{5})/([a-z]{2})(\d+)' . self::REST_OF_URL . '~i'
                => fn(array $m) => $m[1] === '20' . $session_lis_id,
            '~(?:https?:)?//(?:www\.)?richmondsunlight\.com/bill/(\d{4})/([a-z]{2})(\d+)' . self::REST_OF_URL . '~i'
                => fn(array $m) => (int) $m[1] === $session_year,
        ];
        foreach ($links as $pattern => $is_current) {
            $text = (string) preg_replace_callback($pattern, function (array $m) use (&$found, $is_current) {
                if ($is_current($m) && in_array(strtolower($m[2]), self::TYPES, true)) {
                    $found[strtolower($m[2]) . (int) $m[3]] = true;
                }
                return ' ';
            }, $text);
        }

        // Bills by number: "HB123," "SB 42."
        if (preg_match_all('/\b(HB|SB|HJ|SJ|HR|SR)\s*(\d+)\b/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $found[strtolower($match[1]) . $match[2]] = true;
            }
        }

        // Bills by name: "House Bill 123," "Senate Joint Resolution 10."
        if (preg_match_all('/\b(House|Senate)\s+(Bill|Joint Resolution|Resolution)\s+(\d+)\b/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower($match[1] . ' ' . $match[2]);
                if (isset(self::TYPES[$key])) {
                    $found[self::TYPES[$key] . $match[3]] = true;
                }
            }
        }

        $bills = array_keys($found);
        sort($bills);

        return $bills;
    }
}
