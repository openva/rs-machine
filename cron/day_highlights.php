<?php

/*
 * Choose recent days' highlights: score each bill that something was done to on a day, and store
 * the scores, and the day's highlights, in day_highlights (see richmondsunlight.com's
 * htdocs/includes/class.DayHighlights.php, which is in includes/, and its
 * docs/minutes-replacement.md). The day pages show them, through the API.
 *
 * A day's scores change as its votes, video, news, and comments arrive, which takes days. So each
 * run scores today and yesterday, and the 3 a.m. run scores the past two weeks. Days before that
 * stay as they are. (utilities/backfill_day_highlights.php scores earlier days.)
 */

if (!class_exists('DayHighlights')) {
    $log->put('day_highlights: includes/class.DayHighlights.php is missing, so days’ highlights weren’t chosen.', 6);
    return;
}

$days_back = (int) date('G') < 6 ? 14 : 1;

$days = 0;
$highlights = 0;
for ($i = 0; $i <= $days_back; $i++) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    try {
        $count = DayHighlights::save($date);
    } catch (Throwable $e) {
        $log->put('day_highlights: Couldn’t choose the highlights of ' . $date . ': ' . $e->getMessage(), 6);
        continue;
    }
    if ($count !== null) {
        $days++;
        $highlights += $count;
    }
}

$log->put('day_highlights: Chose ' . $highlights . ' highlights for ' . $days . ' days.', $days > 0 ? 2 : 1);
