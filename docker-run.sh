#!/bin/bash
set -e

# Stand it up
docker compose build && docker compose up -d

# Wait for MariaDB to be available
while ! nc -z localhost 3306; do sleep 1; done

# Run the setup script
docker exec rs_machine /home/ubuntu/rs-machine/deploy/docker-setup.sh
