#!/bin/bash
set -euo pipefail

cd /home/ubuntu/rs-machine/

# Ensure required directories exist
mkdir -p includes

# Preserve local files before wiping includes.
backup_dir="/tmp/rs-machine"
mkdir -p "$backup_dir"
if [ -d includes ] && [ -n "$(ls -A includes 2>/dev/null)" ]; then
    # Always preserve settings.local.inc.php if it exists
    if [ -f "includes/settings.local.inc.php" ]; then
        cp "includes/settings.local.inc.php" "$backup_dir"/
    fi

    # Preserve locally modified class.*.php files (newer than the modal timestamp).
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

# Get all functions from the main repo (clone or update to avoid wasteful downloads)
if [ ! -d "richmondsunlight.com" ]; then
    git clone -b deploy https://github.com/openva/richmondsunlight.com.git
else
    cd richmondsunlight.com && git pull && cd ..
fi

rm -Rf includes
mkdir -p includes

includes_source=""
if [ -d "richmondsunlight.com/htdocs/includes" ]; then
    includes_source="richmondsunlight.com/htdocs/includes"
elif [ -d "richmondsunlight.com/includes" ]; then
    includes_source="richmondsunlight.com/includes"
else
    echo "Could not locate includes/ in richmondsunlight.com checkout." >&2
    exit 1
fi

cp "$includes_source"/*.php includes/

# Restore preserved files if any.
if [ -d "$backup_dir" ] && [ -n "$(ls -A "$backup_dir" 2>/dev/null)" ]; then
    cp "$backup_dir"/class.*.php includes/ 2>/dev/null || true
    cp "$backup_dir"/settings.local.inc.php includes/ 2>/dev/null || true
fi

# Install Composer dependencies (only if needed)
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ] || [ "composer.lock" -nt "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    composer install
else
    echo "Composer dependencies up to date, skipping install"
fi

# Move over the settings file.
cp deploy/settings-docker.inc.php includes/settings.inc.php

# Add test data
cp deploy/tests/data/bills.csv cron/bills.csv
