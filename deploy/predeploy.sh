#!/bin/bash

# Change the timezone to Eastern
sudo timedatectl set-timezone America/New_York

# Update the OS
sudo apt-get update
sudo apt-get -y upgrade

# Install necessary packages.
sudo apt-get install -y php-mysql php-memcached php-xml composer zip ruby awscli npm expect \
    memcached poppler-utils
sudo npm i -g csvtojson
