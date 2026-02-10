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

$sql = 'DELETE FROM logs WHERE level = :level AND date < :cutoff';
$stmt = $GLOBALS['dbh']->prepare($sql);

$total_deleted = 0;

foreach ($retention as $level => $days) {

    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

    $stmt->bindParam(':level', $level, PDO::PARAM_INT);
    $stmt->bindParam(':cutoff', $cutoff, PDO::PARAM_STR);
    $stmt->execute();

    $total_deleted += $stmt->rowCount();
}

$log->put('log_prune: Deleted ' . $total_deleted . ' old log messages.', 2);
