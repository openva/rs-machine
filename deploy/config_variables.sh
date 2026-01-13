#!/bin/bash
#==================================================================================
# Uses environment variables within GitHub to populate includes/settings.inc.php
# prior to deployment.
#==================================================================================

# Define the list of environmental variables that we need to populate during deployment.
variables=(
	LIS_FTP_USERNAME
	LIS_FTP_PASSWORD
	PDO_DSN
	PDO_SERVER
	PDO_USERNAME
	PDO_PASSWORD
	MYSQL_DATABASE
	GMAPS_KEY
	OPENSTATES_KEY
	OPENVA_KEY
	VA_DECODED_KEY
	LIS_KEY
	MAPBOX_TOKEN
	MEMCACHED_SERVER
	OPENAI_KEY
	SLACK_WEBHOOK
	AWS_SECRET_KEY
	AWS_ACCESS_KEY
)

# Iterate over the variables and make sure that they're all populated.
for i in "${variables[@]}"
do
	if [ -z "${!i}" ]; then
		echo "GitHub Actions has no value set for $i -- aborting"
		exit 1
	fi
done

# Now iterate over again and perform the replacement.
cp includes/settings-default.inc.php includes/settings.inc.php
for i in "${variables[@]}"
do
	sed -i -e "s|define('$i', '')|define('$i', '${!i}')|g" includes/settings.inc.php
done
