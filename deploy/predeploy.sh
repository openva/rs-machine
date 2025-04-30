#!/bin/bash

# Change the timezone to Eastern
sudo timedatectl set-timezone EST

# Remove all PHP packages (they may well be PHP 7)
#sudo apt-get -y purge `dpkg -l | grep php| awk '{print $2}' |tr "\n" " "`

# Use repo for PHP 5.6.
#sudo add-apt-repository -y ppa:ondrej/php

# Update the OS
sudo apt-get update
sudo apt-get -y upgrade

# Install necessary packages.
sudo apt-get install -y php-mysql php-memcached php-xml composer zip ruby awscli npm expect \
    memcached poppler-utils
sudo npm i -g csvtojson

# Allow "node" to invoke Node.js (as csvtojson requires).
sudo ln -s /usr/bin/nodejs /usr/bin/node
