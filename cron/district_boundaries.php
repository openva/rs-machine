<?php

/*
 * Get every current district
 */
$sql = $dbh->query('SELECT
                        id,
                        chamber,
                        number
                    FROM
                        districts
                    WHERE
                        date_ended IS NULL');

/*
 * Prepare our boundary-insertion SQL statement
 */
$update_stmt = $dbh->prepare('UPDATE districts
                                SET boundaries = :boundaries
                                WHERE id = :district_id');

/*
 * Iterate through districts
 */
while ($district = $stmt->fetch())
{

    if ($district['chamber'] == 'house')
    {
        $c = 'l';
    }
    else
    {
        $c = 'u';
    }

    $url = 'https://data.openstates.org/boundaries/2018/ocd-division/country:us/state:va/sld' . $c . ':' . $district['number'] . '.json';
    $json = get_content($url);

    /*
    * If this is valid JSON.
    */
    if ($json != FALSE)
    {

        $district_data = json_decode($json);

        /*
        * Swap lat/lon to X/Y.
        */
        foreach ($district_data->shape->coordinates[0][0] as &$pair)
        {
            $tmp[0] = $pair[1];
            $tmp[1] = $pair[0];
            $pair = $tmp;
        }
    }

    /*
     * Save the boundary data.
     */
    $update_stmt->bindParam(':boundaries', $district_data);
    $update_stmt->bindParam(':district_id', $district['id']);
    $result = $update_stmt->execute();
                                        
}
