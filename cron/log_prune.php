<?php

/*
 * Set the retention periods for each log level (in days)
 */
$retention = [
    1 => 1,
    2 => 2,
    3 => 3,
    4 => 7,
    5 => 14,
    6 => 30,
    7 => 180,
    8 => 365,
];

/*
 * Delete in batches, each its own short statement. One large DELETE locks the logs table's index
 * for as long as it runs, holding up every task that writes a log message, and if the task's time
 * limit stops it, everything it deleted is rolled back.
 */
$batch = 10000;
$sql = 'DELETE FROM logs WHERE level = :level AND date < :cutoff LIMIT ' . $batch;
$stmt = $GLOBALS['dbh']->prepare($sql);

$total_deleted = 0;

foreach ($retention as $level => $days) {

    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

    $stmt->bindParam(':level', $level, PDO::PARAM_INT);
    $stmt->bindParam(':cutoff', $cutoff, PDO::PARAM_STR);
    do {
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $total_deleted += $deleted;
    } while ($deleted === $batch);
}

$log->put('log_prune: Deleted ' . $total_deleted . ' old log messages.', 2);
