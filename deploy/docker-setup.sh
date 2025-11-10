#!/bin/bash
set -euo pipefail

cd /home/ubuntu/rs-machine/

# Ensure required directories exist
mkdir -p includes

# Get all functions from the main repo
git clone -b deploy https://github.com/openva/richmondsunlight.com.git
cd richmondsunlight.com && composer install && cd ..
cp richmondsunlight.com/htdocs/includes/*.php includes/
rm -Rf richmondsunlight.com

# Install Composer dependencies
composer install

# Move over the settings file.
cp deploy/settings-docker.inc.php includes/settings.inc.php

# Add test data
cp deploy/tests/data/bills.csv cron/bills.csv
