#!/bin/bash
set -e

# Prepare data for the database

# Refresh includes/ if missing or older than 12 hours (720 minutes).
needs_includes_refresh=false
if [ ! -d "includes" ]; then
    needs_includes_refresh=true
elif find includes -maxdepth 0 -mmin +720 -print -quit | grep -q "includes"; then
    needs_includes_refresh=true
fi

if [ "$needs_includes_refresh" = true ]; then
    echo "Refreshing includes/ from richmondsunlight.com (stale or missing)."
    tmp_dir="$(mktemp -d)"
    git clone -b deploy https://github.com/openva/richmondsunlight.com.git "$tmp_dir"
    rm -rf includes
    mkdir -p includes
    cp "$tmp_dir"/htdocs/includes/*.php includes/
    rm -rf "$tmp_dir"
fi

# If the database.sql doesn't exist, create it
if [ ! -f "deploy/database.sql" ]; then

    git clone -b deploy https://github.com/openva/richmondsunlight.com.git

    # Concatenate the database dumps into a single file, for MariaDB to load
    cd richmondsunlight.com/deploy/
    cat mysql/structure.sql mysql/basic-contents.sql mysql/test-records.sql > ../../deploy/database.sql
    cd ..

    rm -Rf richmondsunlight.com

fi

# Stand it up
docker compose build && docker compose up -d

# Wait for MariaDB to be available inside the container
echo "Waiting for MariaDB (rs_machine_db) to report healthy..."
until docker exec rs_machine_db mysqladmin ping -h localhost --silent >/dev/null 2>&1; do
    sleep 1
done

# Run the setup script
docker exec -d rs_machine /home/ubuntu/rs-machine/deploy/docker-setup.sh
