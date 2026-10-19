<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * Restores characters that LIS's BILLS.CSV export destroys in bill catch lines.
 *
 * The CSV is pure ASCII: LIS replaces every non-ASCII character with a literal "?" when it
 * generates the file, so "Damaré" arrives as "Damar?", "Glory’s" as "Glory?s", "REALTORS®" as
 * "REALTORS?" and "2024–2025" as "2024?2025". The original character is gone before we ever
 * download the file, so no amount of re-encoding can recover it.
 *
 * The LIS API has the same titles intact, as UTF-8, on each version of the bill's text. When a
 * catch line contains a "?", we fetch those titles and use one that is identical to the CSV's
 * except where the CSV has a "?". That check means we only ever restore lost characters; we never
 * substitute a title that differs in any other way.
 */
class CatchLineRepair
{
    private const TEXT_ENDPOINT =
        'https://lis.virginia.gov/LegislationText/api/GetLegislationTextByIDAsync';

    /**
     * Whether a catch line from the CSV may have lost characters. Catch lines essentially never
     * contain a genuine question mark, so any "?" is treated as a casualty of the export.
     */
    public static function isDamaged(string $catchLine): bool
    {
        return str_contains($catchLine, '?');
    }

    /**
     * Whether $apiTitle is what LIS would have written to the CSV as $csvTitle, i.e. they are the
     * same once each non-ASCII character in $apiTitle is replaced with "?".
     */
    public static function matches(string $csvTitle, string $apiTitle): bool
    {
        $masked = preg_replace('/[^\x00-\x7F]/u', '?', $apiTitle);
        if ($masked === null) {
            return false; // Not valid UTF-8.
        }

        return self::normalize($masked) === self::normalize($csvTitle);
    }

    /**
     * Pick the intact title from a GetLegislationTextByIDAsync response, preferring the most
     * recent version of the bill.
     *
     * @param array<string, mixed> $response Decoded JSON response.
     */
    public static function pickTitle(string $csvTitle, array $response): ?string
    {
        $texts = $response['TextsList'] ?? [];
        if (!is_array($texts)) {
            return null;
        }

        usort($texts, fn($a, $b) => strcmp($b['VersionDate'] ?? '', $a['VersionDate'] ?? ''));

        foreach ($texts as $text) {
            foreach (['EditTitle', 'DraftTitle'] as $field) {
                if (!is_string($text[$field] ?? null)) {
                    continue;
                }

                // Older sessions' titles are wrapped in markup, e.g. <p class=ldtitle>…</p>.
                $title = html_entity_decode(strip_tags($text[$field]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (self::matches($csvTitle, $title)) {
                    return self::normalize($title);
                }
            }
        }

        return null;
    }

    /**
     * Retrieve the intact title for a bill from the LIS API.
     *
     * @return string|null The repaired title, or null if no version's title corresponds to
     *                     $csvTitle.
     *
     * @throws \RuntimeException If the API could not be reached or returned something unusable,
     *                           so that the caller can try again later.
     */
    public static function fetch(int $lisId, string $csvTitle, string $apiKey): ?string
    {
        $ch = curl_init(self::TEXT_ENDPOINT . '?legislationID=' . $lisId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['WebAPIKey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new \RuntimeException('LIS API returned HTTP ' . $status . ' for legislation ID '
                . $lisId);
        }

        $response = json_decode($body, true);
        if (!is_array($response)) {
            throw new \RuntimeException('LIS API returned invalid JSON for legislation ID '
                . $lisId);
        }

        return self::pickTitle($csvTitle, $response);
    }

    private static function normalize(string $title): string
    {
        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }
}
