#!/bin/bash

cd /home/ubuntu/rs-machine/

# Install Composer dependencies
composer install

# Move over the settings file.
cp deploy/settings-docker.inc.php includes/settings.inc.php

