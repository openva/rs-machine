#!/bin/bash

# Change the timezone to Eastern
sudo timedatectl set-timezone America/New_York

# Update the OS
sudo apt-get update
sudo apt-get -y upgrade

# Install PHP 8.4, the version the website's server, CI and local Docker run. Ubuntu 24.04's own
# packages are 8.3, so it's from packages.sury.org, by version, never the unversioned php-*
# packages, which would get 8.3 (or whatever the repository makes its newest). With 8.3, Composer's
# platform check stops every task, since the dependencies are locked to versions that need 8.4.
# The repository is added as its README (https://packages.sury.org/php/README.txt) says. It
# replaces ppa:ondrej/php, which is frozen and no longer updated.
if [ ! -f /etc/apt/sources.list.d/php.list ]; then
    sudo apt-get -y install lsb-release ca-certificates curl
    curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
        | sudo tee /etc/apt/sources.list.d/php.list > /dev/null
fi
if ls /etc/apt/sources.list.d/ondrej-ubuntu-php-* > /dev/null 2>&1; then
    sudo add-apt-repository --remove -y ppa:ondrej/php
fi
sudo apt-get update
sudo apt-get install -y php8.4-cli php8.4-curl php8.4-gd php8.4-mysql php8.4-mbstring php8.4-xml \
    php8.4-zip php8.4-memcached
sudo update-alternatives --set php /usr/bin/php8.4

# Install necessary packages.
sudo apt-get install -y composer zip ruby awscli npm expect poppler-utils mariadb-client
sudo npm i -g csvtojson
