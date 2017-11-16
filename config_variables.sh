#!/bin/bash

variables=(LIS_FTP_USERNAME
	LIS_FTP_PASSWORD)
#	PDO_DSN
#	PDO_SERVER
#	PDO_USERNAME
#	PDO_PASSWORD
#	GMAPS_KEY
#	YAHOO_KEY
#	OPENSTATES_KEY
#	OPENVA_KEY
#	VA_DECODED_KEY
#	MAPBOX_TOKEN
#   MEMCACHED_SERVER
#	PUSHOVER_KEY
#	SLACK_WEBHOOK
#)

# Iterate over the variables and make sure that they're all populated.
echo Password: $LIS_FTP_PASSWORD
for i in "${variables[@]}"
do
	if [ -z "${!i}" ]
		echo "$i not set -- aborting"
		then exit 1
	fi
done

# Now iterate over again and perform the replacement.
for i in "${variables[@]}"
do
	sed -i -e 's/define(\'$i\', \'\')/define(\'${!i}\', \'\')/g' includes/settings.inc.php
done
