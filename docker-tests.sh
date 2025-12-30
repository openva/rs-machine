#!/usr/bin/env bash

set -euo pipefail

CONTAINER_NAME="rs_machine"
CONTAINER_WORKDIR="/home/ubuntu/rs-machine"

if ! docker ps --filter "name=^${CONTAINER_NAME}$" --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
  echo "Docker container \"${CONTAINER_NAME}\" is not running. Start it before running tests."
  exit 1
fi

echo "Running tests inside Docker container \"${CONTAINER_NAME}\"..."

tests_failed=0

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

# Lint all PHP files (syntax check)
echo "Linting PHP files..."
set +e
lint_output=$(docker exec "${CONTAINER_NAME}" /bin/sh -c "
  cd ${CONTAINER_WORKDIR} \
    && find . -path './includes/vendor' -prune -o -name '*.php' -type f -print0 \
    | xargs -0 -n1 php -l
")
lint_status=$?
set -e
printf "%s\n" "$lint_output"
if [ $lint_status -ne 0 ]; then
  tests_failed=1
elif echo "$lint_output" | grep -q "Errors parsing"; then
  tests_failed=1
fi

# Run PHPUnit suite (prefer vendor binary, fallback to global phpunit). Fail if neither exists.
PHPUNIT_VENDOR_PATH="${CONTAINER_WORKDIR}/includes/vendor/bin/phpunit"
PHPUNIT_FALLBACK_BIN="phpunit"

if docker exec "${CONTAINER_NAME}" test -x "${PHPUNIT_VENDOR_PATH}"; then
  PHPUNIT_BIN="${PHPUNIT_VENDOR_PATH}"
elif docker exec "${CONTAINER_NAME}" command -v ${PHPUNIT_FALLBACK_BIN} >/dev/null 2>&1; then
  PHPUNIT_BIN="${PHPUNIT_FALLBACK_BIN}"
else
  echo "PHPUnit not available in container (looked for ${PHPUNIT_VENDOR_PATH} and ${PHPUNIT_FALLBACK_BIN})."
  tests_failed=1
  PHPUNIT_BIN=""
fi

if [ -n "$PHPUNIT_BIN" ]; then
  echo "Running PHPUnit suite with ${PHPUNIT_BIN}..."
  set +e
  phpunit_output=$(docker exec "${CONTAINER_NAME}" "${PHPUNIT_BIN}" --testdox 2>&1)
  phpunit_status=$?
  set -e
  printf "%s\n" "$phpunit_output"
  if [ $phpunit_status -ne 0 ] || echo "$phpunit_output" | grep -qE "FAILURES!|ERRORS!"; then
    tests_failed=1
  fi
fi

# Run standalone PHP test scripts (those not based on PHPUnit)
for test_script in deploy/tests/*.php; do
  [ -e "$test_script" ] || continue
  if grep -q 'PHPUnit\\Framework\\TestCase' "$test_script"; then
    continue
  fi

  echo "Executing ${test_script}..."
  set +e
  script_output=$(docker exec "${CONTAINER_NAME}" php "${CONTAINER_WORKDIR}/${test_script}" 2>&1)
  script_status=$?
  set -e
  printf "%s\n" "$script_output"
  if [ $script_status -ne 0 ] || echo "$script_output" | grep -qi "Failure:"; then
    tests_failed=1
  fi
done

echo "All tests completed."

# Summarize outcome from collected statuses and outputs.
if [ "$tests_failed" -eq 0 ]; then
  echo "TEST SUITE RESULT: ✅ All tests passed."
else
  echo "TEST SUITE RESULT: ❌ Some tests failed. See output above."
fi
