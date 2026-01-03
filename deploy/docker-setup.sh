#!/bin/bash
set -euo pipefail

cd /home/ubuntu/rs-machine/

# Ensure required directories exist
mkdir -p includes

# Preserve locally modified class.*.php files (newer than the modal timestamp) before wiping includes.
backup_dir="/tmp/rs-machine"
mkdir -p "$backup_dir"
if [ -d includes ] && [ -n "$(ls -A includes 2>/dev/null)" ]; then
    # Build histogram of modification times (seconds).
    mapfile -t mtimes < <(find includes -type f -name '*.php' -printf '%T@\n' 2>/dev/null | awk '{printf "%.0f\n",$1}' )
    if [ "${#mtimes[@]}" -gt 0 ]; then
        modal_ts=$(printf "%s\n" "${mtimes[@]}" | sort | uniq -c | sort -nr | head -n1 | awk '{print $2}')
        while IFS= read -r file; do
            ts=$(stat -c %Y "$file" 2>/dev/null || stat -f %m "$file" 2>/dev/null || echo 0)
            if [ "$ts" -gt "$modal_ts" ]; then
                cp "$file" "$backup_dir"/
            fi
        done < <(find includes -type f -name 'class.*.php' 2>/dev/null)
    fi
fi

# Get all functions from the main repo
rm -rf richmondsunlight.com
git clone -b deploy https://github.com/openva/richmondsunlight.com.git
cd richmondsunlight.com && composer install && cd ..
rm -Rf includes
mkdir -p includes
cp richmondsunlight.com/htdocs/includes/*.php includes/
rm -Rf richmondsunlight.com

# Restore preserved class.*.php overrides if any.
if [ -d "$backup_dir" ] && [ -n "$(ls -A "$backup_dir" 2>/dev/null)" ]; then
    cp "$backup_dir"/class.*.php includes/ 2>/dev/null || true
fi

# Install Composer dependencies
composer install

# Move over the settings file.
cp deploy/settings-docker.inc.php includes/settings.inc.php

# Add test data
cp deploy/tests/data/bills.csv cron/bills.csv
