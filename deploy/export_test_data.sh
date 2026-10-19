#!/bin/bash
#==================================================================================
# Export the sample of the database that richmondsunlight.com's local tests
# (tests/local/) run against, and upload it to S3, where that repo's scheduled
# "Local Tests" workflow gets it.
#
# deploy/database_export.sh is richmondsunlight.com's, copied here during
# deployment (see .github/workflows/deploy.yml).
#==================================================================================
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"

settings_file="$repo_root/includes/settings.inc.php"
export_script="$script_dir/database_export.sh"
destination="s3://deploy.richmondsunlight.com/test-data/local-test-records.sql.gz"

for file in "$settings_file" "$export_script"; do
    if [ ! -f "$file" ]; then
        echo "Missing $file" >&2
        exit 1
    fi
done

# The export script reads the database's connection details from these
eval "$(
    php -r "include '$settings_file';
        echo 'export MYSQL_DATABASE=' . escapeshellarg(MYSQL_DATABASE) . PHP_EOL;
        echo 'export PDO_SERVER=' . escapeshellarg(PDO_SERVER) . PHP_EOL;
        echo 'export PDO_USERNAME=' . escapeshellarg(PDO_USERNAME) . PHP_EOL;
        echo 'export PDO_PASSWORD=' . escapeshellarg(PDO_PASSWORD) . PHP_EOL;"
)"

# The export script writes into a mysql/ directory beside itself, so run a copy of it elsewhere
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
cp "$export_script" "$work_dir/database_export.sh"
/bin/bash "$work_dir/database_export.sh"

gzip -9 "$work_dir/mysql/local-test-records.sql"
aws s3 cp "$work_dir/mysql/local-test-records.sql.gz" "$destination" --only-show-errors
