<?php

declare(strict_types=1);

namespace RsMachine;

/**
 * Rules for deciding which of a bill's impact statements is current.
 *
 * Each statement's LIS reference ID names the bill version and the agency that wrote it:
 * "SB738S1F122" is agency 122's statement on substitute S1 of SB738, and in data from before 2025
 * "FS1122" is the same thing. An agency writes a new statement for each version of a bill, and
 * LIS sometimes reissues one, publishing a new PDF under the same reference ID.
 *
 * LIS numbers its PDFs in the order that it publishes them, so the highest-numbered PDF is the
 * newest (".../1220077.PDF" is newer than ".../1195986.PDF", whichever host it is on).
 *
 * The website shows only the newest statement from each agency, using the same rule as
 * latestPerAgency(): Bill2::latest_impact_statements() in richmondsunlight.com. Keep the two in
 * agreement.
 */
class ImpactStatements
{
    /**
     * Insert a statement, or update a stored one, but never replace a stored PDF with an older
     * one. MySQL and MariaDB apply ON DUPLICATE KEY UPDATE assignments in order, so `summary` is compared
     * against the stored PDF before `pdf_url` changes. A PDF with the same number but a new URL
     * (LIS has moved PDFs between hosts) is the same statement, so its summary is kept.
     */
    public const UPSERT_SQL = 'INSERT INTO fiscal_impact_statements
            (lis_id, bill_id, pdf_url, date_created)
        VALUES
            (:lis_id, :bill_id, :pdf_url, NOW())
        ON DUPLICATE KEY UPDATE
            summary = IF(:pdf_number_for_summary > ' . self::STORED_PDF_NUMBER_SQL . ', NULL, summary),
            pdf_url = IF(:pdf_number_for_url >= ' . self::STORED_PDF_NUMBER_SQL . ', :new_pdf_url, pdf_url)';

    /**
     * The stored pdf_url's PDF number, by the same rule as pdfNumber(), or 0. The CASE keeps CAST
     * from seeing a non-numeric value, which strict mode would reject.
     */
    public const STORED_PDF_NUMBER_SQL = "(CASE WHEN pdf_url REGEXP '[0-9]+[.][Pp][Dd][Ff]$'
            THEN CAST(REGEXP_SUBSTR(pdf_url, '[0-9]+(?=[.][Pp][Dd][Ff]$)') AS UNSIGNED)
            ELSE 0 END)";

    /**
     * The number of a statement's PDF, which orders statements by when LIS published them, or 0
     * if it has none.
     */
    public static function pdfNumber(?string $pdfUrl): int
    {
        return preg_match('/(\d+)\.pdf$/i', (string) $pdfUrl, $number) ? (int) $number[1] : 0;
    }

    /**
     * The three-digit code of the agency that wrote a statement, or null if the reference ID
     * can't be read.
     */
    public static function agencyCode(string $lisId): ?string
    {
        $lisId = strtoupper(trim($lisId));
        if (
            preg_match('/^[A-Z]+\d+([A-Z][A-Z0-9]*)?F(\d{3})$/', $lisId, $parts)
            || preg_match('/^F([A-Z][A-Z0-9]*)?(\d{3})$/', $lisId, $parts)
        ) {
            return $parts[2];
        }

        return null;
    }

    /**
     * Read FiscalImpactStatements.csv into rows of bill_number (lowercase), lis_id and pdf_url
     * (null when empty), skipping the header and any malformed lines.
     *
     * @return list<array{bill_number: string, lis_id: string, pdf_url: ?string}>
     */
    public static function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        array_shift($lines);

        $rows = [];
        foreach ($lines as $line) {
            $fields = str_getcsv($line, ',', '"', '\\');
            if (count($fields) < 3 || trim((string) $fields[0]) === '' || trim((string) $fields[1]) === '') {
                continue;
            }
            $pdfUrl = trim((string) $fields[2]);
            $rows[] = [
                'bill_number' => strtolower(trim($fields[0])),
                'lis_id'      => trim($fields[1]),
                'pdf_url'     => $pdfUrl === '' ? null : $pdfUrl,
            ];
        }

        return $rows;
    }

    /**
     * Reduce CSV rows to one per bill and reference ID, keeping the newest PDF. The CSV is not in
     * publication order, so when LIS has reissued a statement, the older PDF may come last.
     *
     * @param list<array{bill_number: string, lis_id: string, pdf_url: ?string}> $rows
     * @return list<array{bill_number: string, lis_id: string, pdf_url: ?string}> In CSV order of
     *         each pair's first appearance.
     */
    public static function dedupe(array $rows): array
    {
        $newest = [];
        foreach ($rows as $row) {
            $key = $row['bill_number'] . '|' . $row['lis_id'];
            if (
                !isset($newest[$key])
                || self::pdfNumber($row['pdf_url']) > self::pdfNumber($newest[$key]['pdf_url'])
            ) {
                $newest[$key] = $row;
            }
        }

        return array_values($newest);
    }

    /**
     * Reduce one bill's statements to the newest from each agency: the highest-numbered PDF,
     * then (for statements without one, which come from before 2025) the highest id. Statements
     * whose reference IDs can't be read are all kept.
     *
     * @param array<array{id: int|string, lis_id: string, pdf_url: ?string}> $statements
     * @return list<array> The newest statements, in the order that they were given.
     */
    public static function latestPerAgency(array $statements): array
    {
        $latest = [];
        foreach ($statements as $index => $statement) {
            $key = self::agencyCode((string) $statement['lis_id']) ?? 'unknown-' . $index;
            $rank = [self::pdfNumber($statement['pdf_url'] ?? null), (int) ($statement['id'] ?? 0)];
            if (!isset($latest[$key]) || $rank > $latest[$key]['rank']) {
                $latest[$key] = ['rank' => $rank, 'index' => $index];
            }
        }

        $indexes = array_column($latest, 'index');
        sort($indexes);

        return array_map(fn($index) => $statements[$index], $indexes);
    }

    /**
     * Choose which statements to summarize: those that are the newest from their agency for
     * their bill, have a PDF, and have no summary yet. Superseded statements aren't shown, so
     * summarizing them would be wasted.
     *
     * @param array<array{bill_id: int|string, id: int|string, lis_id: string, pdf_url: ?string,
     *              summary: ?string, views: int|string}> $statements Every statement for each bill
     *        under consideration, not just the unsummarized ones, so that the newest can be found.
     * @return list<array> Up to $limit statements, most-viewed bills first.
     */
    public static function toSummarize(array $statements, int $limit): array
    {
        $byBill = [];
        foreach ($statements as $statement) {
            $byBill[$statement['bill_id']][] = $statement;
        }

        $chosen = [];
        foreach ($byBill as $billStatements) {
            foreach (self::latestPerAgency($billStatements) as $statement) {
                if ($statement['summary'] === null && ($statement['pdf_url'] ?? '') !== '') {
                    $chosen[] = $statement;
                }
            }
        }

        usort($chosen, fn($a, $b) => (int) $b['views'] <=> (int) $a['views']);

        return array_slice($chosen, 0, $limit);
    }
}
