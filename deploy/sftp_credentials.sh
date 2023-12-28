#!/bin/bash

# Set up FTP credentials in the SFTP script.
sed -i -e "s|{LIS_FTP_PASSWORD}|${LIS_FTP_PASSWORD}|g" cron/sftp.sh
sed -i -e "s|{LIS_FTP_USERNAME}|${LIS_FTP_USERNAME}|g" cron/sftp.sh
sed -i -e "s|{LIS_SESSION_ID}|${LIS_SESSION_ID}|g" cron/sftp.sh
