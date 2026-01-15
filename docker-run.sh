#!/bin/bash
set -e

# Ensure Docker daemon is running locally (skip in GitHub Actions)
if [ -z "${GITHUB_ACTIONS:-}" ]; then
    if ! docker info >/dev/null 2>&1; then
        echo "Docker is not running. Please start Docker and try again." >&2
        exit 1
    fi
fi

# Prepare data for the database

# Refresh includes/ from inside the container if missing, empty, or stale (>12h since newest file).
needs_includes_refresh=false
if [ ! -d "includes" ]; then
    needs_includes_refresh=true
else
    newest_epoch=$(python - <<'PY'
import os
root = "includes"
mtimes = []
for dirpath, _, filenames in os.walk(root):
    for name in filenames:
        try:
            mtimes.append(os.path.getmtime(os.path.join(dirpath, name)))
        except OSError:
            pass
if mtimes:
    print(int(max(mtimes)))
PY
)
    if [ -z "$newest_epoch" ]; then
        needs_includes_refresh=true
    else
        now_epoch=$(date +%s)
        age_seconds=$(( now_epoch - newest_epoch ))
        if [ "$age_seconds" -gt $((720 * 60)) ]; then
            needs_includes_refresh=true
        fi
    fi
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

# Run the setup script (handles includes refresh inside container if needed)
if [ "$needs_includes_refresh" = true ]; then
    echo "Refreshing includes/ inside container via deploy/docker-setup.sh"
fi
docker exec rs_machine /home/ubuntu/rs-machine/deploy/docker-setup.sh

# Verify includes were populated (fail fast if setup did not complete).
if [ ! -d "includes" ] || [ -z "$(ls -A includes 2>/dev/null)" ]; then
    echo "includes/ is missing or empty after docker-setup.sh; please check container logs."
    exit 1
fi
