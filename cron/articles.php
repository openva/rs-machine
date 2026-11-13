<?php

/*
 * Fetch news articles from Virginia publications and link them to bills.
 */

$feeds = [
    'https://virginiamercury.com/feed/localFeed/' => 'Virginia Mercury',
    'https://cardinalnews.org/feed/' => 'Cardinal News',
    'https://www.vpm.org/index.rss' => 'Virginia Public Media',
    'https://www.virginiascope.com/feed/' => 'Virginia Scope',
    'https://www.roanokerambler.com/rss/' => 'The Roanoke Rambler',
    'https://virginiabusiness.com/feed/' => 'Virginia Business',
    'https://www.richmonder.org/rss/' => 'The Richmonder',
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
         * Find bill references: by number, by name, and by links to this session's bills (see
         * RsMachine\BillReferences).
         */
        $found_bills = array_flip(\RsMachine\BillReferences::find($search_text, (string) SESSION_LIS_ID, (int) SESSION_YEAR));

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

if ($total_inserted > 0) {
    $log->put('Articles: Total new article-bill links: ' . $total_inserted, $log_level);
}
