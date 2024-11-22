#!/bin/bash

# Put the session ID into the SFTP path, so we fetch the correct LIS CSV
SESSION_LIS_ID=$(grep -oP "SESSION_LIS_ID', '\K([0-9]{3})" includes/settings.inc.php)
sed -i -e "s|{SESSION_LIS_ID}|${SESSION_LIS_ID}|g" cron/fetch_csv.sh
