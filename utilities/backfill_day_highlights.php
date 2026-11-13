<?php

/*
 * Choose the highlights of every past day (or of the days in some years), which cron/
 * day_highlights.php doesn't do: it only scores the past two weeks. Run it once, after
 * day_highlights is created, and again after the weights change (they're in
 * includes/class.DayHighlights.php).
 *
 * Bills that an admin has hidden from a day's highlights stay hidden.
 *
 * Usage: php utilities/backfill_day_highlights.php [first year] [last year]
 *   The years default to 2008 (the first with day pages) and this year. Each day takes a fraction
 *   of a second, and there are about 150 a year.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

$first = (int) ($argv[1] ?? 2008);
$last = (int) ($argv[2] ?? date('Y'));
if ($first < 2000 || $last < $first) {
    fwrite(STDERR, "Usage: php utilities/backfill_day_highlights.php [first year] [last year]\n");
    exit(1);
}

$database = new Database();
$database->connect_mysqli();

for ($year = $first; $year <= $last; $year++) {
    $days = Day::list_for_api($year);
    $highlights = 0;
    foreach ($days as $day) {
        try {
            $highlights += (int) DayHighlights::save($day['date']);
        } catch (Throwable $e) {
            fwrite(STDERR, $day['date'] . ': ' . $e->getMessage() . "\n");
        }
        // Spare the database, which the site is using too.
        usleep(100000);
    }
    echo $year . ': ' . count($days) . ' days, ' . $highlights . " highlights\n";
}
