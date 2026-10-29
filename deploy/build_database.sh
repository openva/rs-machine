#!/bin/bash
#
# Build deploy/database.sql, which the MariaDB container loads when it's first created.
#
# The test database is richmondsunlight.com's, so it's assembled from that repository's deploy
# branch (cloned to richmondsunlight.com/, as for includes/) rather than kept here, where it would
# drift. Changes to the schema or the test data belong in that repository's deploy/mysql/.
#
# The MariaDB container loads this only when its volume is new, so after rebuilding, reload it
# with: docker compose down -v && ./docker-run.sh

set -euo pipefail

cd "$(dirname "$0")/.."

source_dir="richmondsunlight.com"
output="deploy/database.sql"

# Assembled in this order. The fixtures go last, so their rows are added to the test records.
# (The video fixtures aren't needed here.)
files=(
    structure.sql
    basic-contents.sql
    test-records.sql
    fiscal-impact-fixtures.sql
)

if [ ! -d "$source_dir" ]; then
    git clone --depth 1 -b deploy https://github.com/openva/richmondsunlight.com.git "$source_dir"
elif ! (cd "$source_dir" && git pull --ff-only --quiet); then
    # Keep going with the copy we have, if it's complete, rather than failing over a network blip.
    echo "Could not update $source_dir; building the database from the copy already here." >&2
fi

for file in "${files[@]}"; do
    if [ ! -s "$source_dir/deploy/mysql/$file" ]; then
        echo "$source_dir/deploy/mysql/$file is missing or empty, so the database can't be built." >&2
        exit 1
    fi
done

# The local-only sample of production data, which the tests in deploy/tests/local/ need. It isn't in
# Git, because this repository is public. rs-machine's export_test_data.sh uploads it weekly; get it
# with:
#   aws s3 cp s3://deploy.richmondsunlight.com/test-data/local-test-records.sql.gz - | gunzip \
#       > deploy/local-test-records.sql
sample="deploy/local-test-records.sql"

# Write to a temporary file, so a failure doesn't leave a partial database.sql behind.
tmp_file="$(mktemp "$output.XXXXXX")"
trap 'rm -f "$tmp_file"' EXIT
{
    for file in "${files[@]}"; do
        cat "$source_dir/deploy/mysql/$file"
        # A dump that doesn't end with a newline would run into the next one's first line.
        printf '\n'
    done
    if [ -s "$sample" ]; then
        cat "$sample"
        printf '\n'
    fi
} > "$tmp_file"
chmod 644 "$tmp_file"
mv "$tmp_file" "$output"

echo "Built $output from richmondsunlight.com $(cd "$source_dir" && git log -1 --format='%h (%ad)' --date=short)$([ -s "$sample" ] && echo ', with the local sample')."
