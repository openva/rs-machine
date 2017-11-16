#!/bin/bash

variables=(
	LIS_FTP_USERNAME
	LIS_FTP_PASSWORD
	PDO_DSN
	PDO_SERVER
	PDO_USERNAME
	PDO_PASSWORD
	GMAPS_KEY
	YAHOO_KEY
	OPENSTATES_KEY
	OPENVA_KEY
	VA_DECODED_KEY
	MAPBOX_TOKEN
	MEMCACHED_SERVER
	PUSHOVER_KEY
	SLACK_WEBHOOK
)

# Iterate over the variables and make sure that they're all populated.
for i in "${variables[@]}"
do
	if [ -z "${!i}" ]; then
		echo "Travis CI has no value set for $i -- aborting"
	fi
done

# Now iterate over again and perform the replacement.
for i in "${variables[@]}"
do
	sed -i -e "s/define('$i', '')/define('$i', '${!i}')/g" includes/settings.inc.php
	echo "s/define('$i', '')/define('$i', '${!i}')/g"
done

cat includes/settings.inc.php
