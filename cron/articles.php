<?php

/*
 * Fetch news articles from Virginia publications and link them to bills.
 */

$feeds = [
    'https://virginiamercury.com/feed/localFeed/' => 'Virginia Mercury',
    'https://cardinalnews.org/feed/' => 'Cardinal News',
];

/*
 * Build a lookup array of bill numbers for the current session.
 */
$sql = 'SELECT id, number FROM bills WHERE session_id = ' . SESSION_ID;
$stmt = $GLOBALS['dbh']->prepare($sql);
$stmt->execute();
$bill_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bills = [];
foreach ($bill_list as $bill) {
    $bills[strtolower($bill['number'])] = $bill['id'];
}

if (count($bills) == 0) {
    $log->put('articles: No bills found for current session.', 5);
    return;
}

/*
 * Map full bill-type names to their abbreviations.
 */
$type_map = [
    'house bill' => 'hb',
    'senate bill' => 'sb',
    'house joint resolution' => 'hj',
    'senate joint resolution' => 'sj',
    'house resolution' => 'hr',
    'senate resolution' => 'sr',
];

/*
 * Prepare the INSERT statement.
 */
$insert_sql = 'INSERT IGNORE INTO bills_news
               SET bill_id = :bill_id,
                   url = :url,
                   title = :title,
                   publication = :publication,
                   date = :date,
                   date_created = NOW()';
$insert_stmt = $GLOBALS['dbh']->prepare($insert_sql);

$total_inserted = 0;

foreach ($feeds as $feed_url => $publication) {

    $xml_raw = get_content($feed_url);
    if ($xml_raw === false) {
        $log->put('articles: Could not fetch feed for ' . $publication, 5);
        continue;
    }

    $xml = simplexml_load_string($xml_raw);
    if ($xml === false) {
        $log->put('articles: Could not parse feed for ' . $publication, 5);
        continue;
    }

    $feed_inserted = 0;

    foreach ($xml->channel->item as $item) {

        $title = (string) $item->title;
        $link = (string) $item->link;
        $pub_date = date('Y-m-d', strtotime((string) $item->pubDate));

        $description = (string) $item->description;

        /*
         * Get content:encoded if available.
         */
        $content = '';
        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['content'])) {
            $content_ns = $item->children($namespaces['content']);
            $content = (string) $content_ns->encoded;
        }

        /*
         * Combine all text fields for searching.
         */
        $search_text = $title . ' ' . $description . ' ' . $content;

        /*
         * Find bill references.
         */
        $found_bills = [];

        // Abbreviated patterns: HB123, SB 42, etc.
        if (preg_match_all('/\b(HB|SB|HJ|SJ|HR|SR)\s*(\d+)\b/i', $search_text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $normalized = strtolower($match[1]) . $match[2];
                $found_bills[$normalized] = true;
            }
        }

        // Full name patterns: House Bill 123, Senate Joint Resolution 10, etc.
        if (preg_match_all('/\b(House|Senate)\s+(Bill|Joint Resolution|Resolution)\s+(\d+)\b/i', $search_text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower($match[1] . ' ' . $match[2]);
                if (isset($type_map[$key])) {
                    $normalized = $type_map[$key] . $match[3];
                    $found_bills[$normalized] = true;
                }
            }
        }

        /*
         * Insert a row for each matched bill.
         */
        foreach (array_keys($found_bills) as $bill_number) {
            if (!isset($bills[$bill_number])) {
                continue;
            }

            $bill_id = $bills[$bill_number];

            $insert_stmt->bindParam(':bill_id', $bill_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':url', $link, PDO::PARAM_STR);
            $insert_stmt->bindParam(':title', $title, PDO::PARAM_STR);
            $insert_stmt->bindParam(':publication', $publication, PDO::PARAM_STR);
            $insert_stmt->bindParam(':date', $pub_date, PDO::PARAM_STR);

            try {
                $insert_stmt->execute();
                if ($insert_stmt->rowCount() > 0) {
                    $feed_inserted++;
                }
            } catch (PDOException $e) {
                $log->put('articles: Insert failed: ' . $e->getMessage(), 5);
                continue;
            }
        }
    }

    $total_inserted += $feed_inserted;
    if ($feed_inserted > 0) {
        $log_level = 3;
    } else {
        $log_level = 2;
    }

    $log->put('Articles: ' . $publication . ': ' . $feed_inserted . ' new article-bill links.', $log_level);
}

$log->put('Articles: Total new article-bill links: ' . $total_inserted, 3);
