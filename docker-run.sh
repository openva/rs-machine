#!/bin/bash
set -e

# Prepare data for the database

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
docker exec rs_machine /app/deploy/docker-setup.sh
