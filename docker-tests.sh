#!/usr/bin/env bash

set -euo pipefail

CONTAINER_NAME="rs_machine"
CONTAINER_WORKDIR="/home/ubuntu/rs-machine"

if ! docker ps --filter "name=^${CONTAINER_NAME}$" --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
  echo "Docker container \"${CONTAINER_NAME}\" is not running. Start it before running tests."
  exit 1
fi

echo "Running tests inside Docker container \"${CONTAINER_NAME}\"..."

# Ensure composer autoload prerequisites inside container
docker exec "${CONTAINER_NAME}" /bin/sh -c "
  set -e
  if [ ! -d /htdocs/includes ]; then
    mkdir -p /htdocs/includes
  fi
  if [ ! -e /htdocs/includes/functions.inc.php ]; then
    ln -sf ${CONTAINER_WORKDIR}/includes/functions.inc.php /htdocs/includes/functions.inc.php
  fi
"

# Run PHPUnit suite if available
if docker exec "${CONTAINER_NAME}" test -x "/app/vendor/bin/phpunit"; then
  echo "Running PHPUnit suite..."
  docker exec "${CONTAINER_NAME}" /app/vendor/bin/phpunit
else
  echo "Skipping PHPUnit suite (vendor/bin/phpunit not found)."
fi

# Run standalone PHP test scripts (those not based on PHPUnit)
for test_script in deploy/tests/*.php; do
  [ -e "$test_script" ] || continue
  if grep -q 'PHPUnit\\Framework\\TestCase' "$test_script"; then
    continue
  fi

  echo "Executing ${test_script}..."
  docker exec "${CONTAINER_NAME}" php "${CONTAINER_WORKDIR}/${test_script}"
done

echo "All tests completed."
