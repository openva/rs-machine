#!/bin/bash

cd ~/rs-machine/

# Set up the crontab
crontab deploy/crontab.txt

# Set permissions properly, since appspec.yml gets this wrong.
chown -R ubuntu:ubuntu /home/ubuntu/

# Log a record of this deployment
echo -e "$(date)\tSuccessful deployment \r\n" >> deploy.log
