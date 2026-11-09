<?php

/**
 * Preemptively cache data
 *
 * Caches the IDs of the current session's bills, by year and number, as the website's
 * Bill2::getid() looks them up ("bill-2027-hb1"). The year is in the key because bill numbers
 * repeat every session.
 *
 * @usage   Must be invoked from within update.php.
 */

/*
 * Connect to Memcached.
 */
$mc = null;
if (MEMCACHED_SERVER != '' && class_exists('Memcached')) {
    $mc = new Memcached();
    $mc->addServer(MEMCACHED_SERVER, MEMCACHED_PORT);
}

if (!($mc instanceof Memcached)) {
    return \RsMachine\TaskResult::skipped('Memcached isn\'t configured');
}

/*
 * Cache every bill ID / number from the current session, setting a far-off expiry date.
 */
$sql = 'SELECT id, number
		FROM bills
		WHERE session_id = ' . SESSION_ID;
$result = mysqli_query($GLOBALS['db'], $sql);
$ids = [];
while ($result !== false && $bill = mysqli_fetch_array($result)) {
    $ids['bill-' . SESSION_YEAR . '-' . $bill['number']] = $bill['id'];
}
if ($ids !== []) {
    $mc->setMulti($ids, (60 * 60 * 24 * 180));
}
