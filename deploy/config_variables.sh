#!/bin/bash
#==================================================================================
# Uses environment variables within Travis CI to populate includes/settings.inc.php
# prior to deployment. This allows secrets (e.g., API keys) to be stored in Travis,
# while the settings file is stored on GitHub.
#==================================================================================

# Define the list of environmental variables that we need to populate during deployment.
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


export LIS_FTP_USERNAME="test"
export LIS_FTP_PASSWORD="test"
export PDO_DSN="test1;test2"
export PDO_SERVER="test"
export PDO_USERNAME="test"
export PDO_PASSWORD="test"
export GMAPS_KEY="test"
export YAHOO_KEY="test"
export OPENSTATES_KEY="test"
export OPENVA_KEY="test"
export VA_DECODED_KEY="test"
export MAPBOX_TOKEN="test"
export MEMCACHED_SERVER="test"
export PUSHOVER_KEY="test"
export SLACK_WEBHOOK="test"

# Iterate over the variables and make sure that they're all populated.
for i in "${variables[@]}"
do
	if [ -z "${!i}" ]; then
		echo "Travis CI has no value set for $i -- aborting"
		exit 1
	fi
done

# Now iterate over again and perform the replacement.
for i in "${variables[@]}"
do
	# Escape any semicolons, since they have a reserved value in sed.
	value=${!i}
	value=${value//;/\;}
	sed -i -e "s|define('$i', '')|define('$i', '$value')|g" ../includes/settings.inc.php
done
