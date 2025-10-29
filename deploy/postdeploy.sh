#!/bin/bash

cd /home/ubuntu/rs-machine/ || exit

# Set up the crontab
crontab deploy/crontab.txt

# Log a record of this deployment
echo -e "$(date)\tSuccessful deployment \r" >> deploy.log
