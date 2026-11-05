<?php

declare(strict_types=1);

namespace RsMachine;

use PDO;

/**
 * The sections of the Code of Virginia that a bill amends, adds or repeals, which are stored in
 * bills_section_numbers.
 *
 * They're named in the bill's enactment clauses: "1. That §§ 9.1-102 and 18.2-422 of the Code of
 * Virginia are amended and reenacted and that the Code of Virginia is amended by adding a section
 * numbered 18.2-422.1 as follows:". Sections cited anywhere else in the bill aren't affected by
 * it, so only the enactment clauses are read.
 */
final class CodeSections
{
    /**
     * A section number: a title (2.2, 8.9A), a hyphen, and a section within it (148.5, 69.40:1).
     */
    private const SECTION = '\d+(?:\.\d+)?[A-Z]?-\d+(?:\.\d+)*(?::\d+(?:\.\d+)*)?';

    /** The most sections that "X through Y" is expanded into. */
    private const MAX_RANGE = 200;

    /**
     * The sections that a bill's text amends, adds or repeals, in the order named.
     *
     * @return string[]
     */
    public static function fromText(string $html): array
    {
        $text = preg_replace('#</p\s*>|<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        $sections = [];
        foreach (preg_split('/\n+/', $text) ?: [] as $paragraph) {
            $paragraph = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
            if (!self::isEnactmentClause($paragraph)) {
                continue;
            }

            // What follows "as follows:" is the law itself, which may cite other sections.
            $paragraph = preg_split('/\bas follows\b/i', $paragraph)[0];

            preg_match_all(
                '/(?<![\w.-])(' . self::SECTION . ')(?:,? through (' . self::SECTION . '))?(?![\w-])/',
                $paragraph,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                foreach (self::expand($match[1], $match[2] ?? '') as $section) {
                    $sections[$section] = true;
                }
            }
        }

        return array_map('strval', array_keys($sections));
    }

    /**
     * Parse the latest text of each of a session's bills that hasn't been parsed yet, and store
     * its sections.
     *
     * @param array<int,int> $parsed Bill ID => the ID of the text whose sections are stored.
     * @param int            $limit  The most bills to parse.
     * @return array<int,int> $parsed, updated, and holding only this session's bills.
     */
    public static function update(PDO $dbh, int $session_id, array $parsed, int $limit): array
    {
        // The latest version of each bill's text.
        $latest = $dbh->prepare(
            'SELECT f.bill_id,
                SUBSTRING_INDEX(GROUP_CONCAT(f.id ORDER BY f.date_introduced DESC, f.id DESC), ",", 1)
            FROM bills_full_text f
            JOIN bills b ON b.id = f.bill_id
            WHERE b.session_id = :session_id AND f.text IS NOT NULL
            GROUP BY f.bill_id'
        );
        $latest->execute([':session_id' => $session_id]);
        $latest = array_map('intval', $latest->fetchAll(PDO::FETCH_KEY_PAIR));

        $text = $dbh->prepare('SELECT text FROM bills_full_text WHERE id = :id');
        $stored = $dbh->prepare('SELECT section_number FROM bills_section_numbers WHERE bill_id = :bill_id');
        $delete = $dbh->prepare('DELETE FROM bills_section_numbers WHERE bill_id = :bill_id');
        $insert = $dbh->prepare(
            'INSERT INTO bills_section_numbers (full_text_id, bill_id, section_number, date_created)
            VALUES (:full_text_id, :bill_id, :section_number, NOW())'
        );
        $relabel = $dbh->prepare(
            'UPDATE bills_section_numbers SET full_text_id = :full_text_id WHERE bill_id = :bill_id'
        );

        $done = 0;
        foreach ($latest as $bill_id => $full_text_id) {
            if ($done >= $limit) {
                break;
            }
            if (($parsed[$bill_id] ?? null) === $full_text_id) {
                continue;
            }
            $done++;

            $text->execute([':id' => $full_text_id]);
            $sections = self::fromText((string) $text->fetchColumn());

            $stored->execute([':bill_id' => $bill_id]);
            $previous = $stored->fetchAll(PDO::FETCH_COLUMN);
            sort($previous);
            $sorted = $sections;
            sort($sorted);

            $dbh->beginTransaction();
            try {
                if ($previous === $sorted) {
                    // The same sections, from a newer version.
                    $relabel->execute([':full_text_id' => $full_text_id, ':bill_id' => $bill_id]);
                } else {
                    $delete->execute([':bill_id' => $bill_id]);
                    foreach ($sections as $section) {
                        $insert->execute([
                            ':full_text_id' => $full_text_id,
                            ':bill_id' => $bill_id,
                            ':section_number' => $section,
                        ]);
                    }
                }
                $dbh->commit();
            } catch (\Throwable $e) {
                $dbh->rollBack();
                throw $e;
            }

            $parsed[$bill_id] = $full_text_id;
        }

        return array_intersect_key($parsed, $latest);
    }

    /**
     * Whether a paragraph is an enactment clause that amends, adds or repeals sections, e.g.,
     * "2. That § 22.1-207.3 of the Code of Virginia is repealed."
     */
    private static function isEnactmentClause(string $paragraph): bool
    {
        return preg_match('/^(?:\d+\.\s*)?That\b/i', $paragraph) === 1
            && stripos($paragraph, 'Code of Virginia') !== false
            && preg_match('/\b(?:amended|repealed)\b/i', $paragraph) === 1;
    }

    /**
     * The sections from one to another ("59.1-148.5 through 59.1-148.8"), when they differ only
     * in their last number. Otherwise, just the two.
     *
     * @return string[]
     */
    private static function expand(string $from, string $to): array
    {
        if ($to === '') {
            return [$from];
        }

        if (
            preg_match('/^(.*?)(\d+)$/', $from, $start) === 1
            && preg_match('/^(.*?)(\d+)$/', $to, $end) === 1
            && $start[1] === $end[1]
            && (int) $end[2] >= (int) $start[2]
            && (int) $end[2] - (int) $start[2] < self::MAX_RANGE
        ) {
            return array_map(
                static fn(int $n): string => $start[1] . $n,
                range((int) $start[2], (int) $end[2])
            );
        }

        return [$from, $to];
    }
}
