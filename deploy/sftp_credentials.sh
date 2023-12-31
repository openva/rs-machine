#!/bin/bash

# Set up FTP credentials in the SFTP script.
sed -i -e "s|{LIS_FTP_PASSWORD}|${LIS_FTP_PASSWORD}|g" cron/sftp.sh
sed -i -e "s|{LIS_FTP_USERNAME}|${LIS_FTP_USERNAME}|g" cron/sftp.sh

# Put the session ID into the SFTP path, so we fetch the correct LIS CSV
SESSION_LIS_ID=$(grep -oP "SESSION_LIS_ID', '\K([0-9]{3})" includes/settings.inc.php)
sed -i -e "s|{SESSION_LIS_ID}|${SESSION_LIS_ID}|g" cron/sftp.sh

