<!doctype html>
<html>
<head>
    <meta http-equiv="refresh" content="5" />
    <title>Resolve Districts</title>
</head>
<body>

<?php

    # INCLUDES
    # Include any files or libraries that are necessary for this specific
    # page to function.
    include_once('../includes/functions.inc.php');
    include_once('../includes/settings.inc.php');

    # DECLARATIVE FUNCTIONS
    # Run those functions that are necessary prior to loading this specific
    # page.
    connect_to_db();

    $sql = 'SELECT id, latitude, longitude
			FROM users
			WHERE state = "VA" AND zip IS NOT NULL AND house_district_id IS NULL
			AND senate_district_id IS NULL
			ORDER BY date_created DESC
			LIMIT 10';
    $result = mysqli_query($GLOBALS['db'], $sql);
while ($user = mysqli_fetch_array($result)) {
    $location = new Location();
    $location->latitude = $user['latitude'];
    $location->longitude = $user['longitude'];
    $districts = $location->coords_to_districts();
    if ($districts !== false) {
        $sql = 'UPDATE users
					SET house_district_id=' . $districts->house . ', senate_district_id=' . $districts->senate . '
					WHERE id=' . $user['id'];
        echo '<p>' . $sql . '</p>';
        mysqli_query($GLOBALS['db'], $sql);
    }
}

?>
</body>
</html>
