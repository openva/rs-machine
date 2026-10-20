#!/bin/bash
#==================================================================================
# Copy the production database (richmondsunlight) to the staging site's database
# (rs-staging), which is on the same server.
#
# Each table is copied within the database server, into a new table beside the
# staging one, and swapped in only once its copy is complete, so a failure leaves
# staging with the last good copy of that table rather than a partial one. A few
# large tables that staging doesn't need in full are cut down (see PARTIAL_TABLES).
#
# Foreign keys aren't copied: CREATE TABLE ... LIKE leaves them out.
#==================================================================================
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
settings_file="$repo_root/includes/settings.inc.php"

SOURCE="richmondsunlight"
DESTINATION="rs-staging"

# Rows copied at a time, for tables with an integer primary key, to keep each
# transaction (and the binary log entry for it) a manageable size
CHUNK_SIZE=5000

# Tables copied only in part: a WHERE clause selecting the rows to copy
# shellcheck disable=SC2016 # The backticks quote SQL column names
declare -A PARTIAL_TABLES=(
    [logs]='FALSE'
    [bills_views]='`date` >= NOW() - INTERVAL 90 DAY'
    [chamber_status]='`date` >= NOW() - INTERVAL 1 YEAR'
)

if [[ ! -f "$settings_file" ]]; then
    echo "Missing $settings_file" >&2
    exit 1
fi

eval "$(
    php -r "include '$settings_file';
        echo 'DB_HOST=' . escapeshellarg(PDO_SERVER) . PHP_EOL;
        echo 'DB_USER=' . escapeshellarg(PDO_USERNAME) . PHP_EOL;
        echo 'DB_PASS=' . escapeshellarg(PDO_PASSWORD) . PHP_EOL;"
)"

# Keep the password off of the command line, where other users can see it
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
options_file="$work_dir/client.cnf"
escaped_password="$(printf '%s' "$DB_PASS" | sed 's/\\/\\\\/g; s/"/\\"/g')"
printf '[client]\nhost=%s\nuser=%s\npassword="%s"\n' "$DB_HOST" "$DB_USER" "$escaped_password" > "$options_file"
chmod 600 "$options_file"

# Run SQL, given on stdin or with -e, and print the results without column names
sql() {
    mariadb --defaults-extra-file="$options_file" --batch --skip-column-names "$@"
}

# Every statement runs with these, so that reading production doesn't lock its rows, and so that
# tables can be dropped and renamed regardless of any foreign keys left over in staging
SESSION='SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED; SET SESSION foreign_key_checks = 0;'

src="\`$SOURCE\`"
dst="\`$DESTINATION\`"

sql -e "CREATE DATABASE IF NOT EXISTS $dst CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

mapfile -t tables < <(sql -e "SELECT table_name FROM information_schema.tables
    WHERE table_schema = '$SOURCE' AND table_type = 'BASE TABLE' ORDER BY table_name")
if (( ${#tables[@]} == 0 )); then
    echo "Found no tables in $SOURCE" >&2
    exit 1
fi

failed=()
for table in "${tables[@]}"; do
    new="\`${table}__new\`"
    old="\`${table}__old\`"
    filter="${PARTIAL_TABLES[$table]:-TRUE}"
    started=$SECONDS

    if ! sql -e "$SESSION
            DROP TABLE IF EXISTS $dst.$new, $dst.$old;
            CREATE TABLE $dst.$new LIKE $src.\`$table\`;"; then
        failed+=("$table")
        continue
    fi

    # Copy in chunks if the primary key is a single integer column, and otherwise all at once
    if ! key="$(sql -e "SELECT c.column_name FROM information_schema.columns c
        JOIN information_schema.key_column_usage k
            ON k.table_schema = c.table_schema AND k.table_name = c.table_name
            AND k.column_name = c.column_name AND k.constraint_name = 'PRIMARY'
        WHERE c.table_schema = '$SOURCE' AND c.table_name = '$table'
            AND c.data_type IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint')
            AND (SELECT COUNT(*) FROM information_schema.key_column_usage
                WHERE table_schema = '$SOURCE' AND table_name = '$table'
                AND constraint_name = 'PRIMARY') = 1")"; then
        failed+=("$table")
        continue
    fi
    copied=true
    if [[ -n "$key" ]]; then
        if ! range="$(sql -e "SELECT IFNULL(MIN(\`$key\`), 0), IFNULL(MAX(\`$key\`), -1)
                FROM $src.\`$table\` WHERE $filter")"; then
            failed+=("$table")
            continue
        fi
        read -r low high <<< "$range"
        for (( start = low; start <= high; start += CHUNK_SIZE )); do
            if ! sql -e "$SESSION
                    INSERT INTO $dst.$new SELECT * FROM $src.\`$table\`
                    WHERE ($filter) AND \`$key\` BETWEEN $start AND $(( start + CHUNK_SIZE - 1 ))"; then
                copied=false
                break
            fi
        done
    elif ! sql -e "$SESSION INSERT INTO $dst.$new SELECT * FROM $src.\`$table\` WHERE $filter"; then
        copied=false
    fi

    # Swap the copy in, in a single step, so that the table is never missing from staging
    if [[ "$copied" == true ]] && sql -e "$SESSION
            CREATE TABLE IF NOT EXISTS $dst.\`$table\` LIKE $dst.$new;
            RENAME TABLE $dst.\`$table\` TO $dst.$old, $dst.$new TO $dst.\`$table\`;
            DROP TABLE $dst.$old;"; then
        rows="$(sql -e "SELECT COUNT(*) FROM $dst.\`$table\`")"
        echo "Copied $table ($rows rows, $(( SECONDS - started ))s)"
    else
        sql -e "$SESSION DROP TABLE IF EXISTS $dst.$new" || true
        failed+=("$table")
    fi
done

# Remove any tables that are no longer in production
mapfile -t stale < <(sql -e "SELECT s.table_name FROM information_schema.tables s
    LEFT JOIN information_schema.tables p
        ON p.table_schema = '$SOURCE' AND p.table_name = s.table_name
    WHERE s.table_schema = '$DESTINATION' AND p.table_name IS NULL")
for table in "${stale[@]}"; do
    sql -e "$SESSION DROP TABLE $dst.\`$table\`" && echo "Removed $table, which isn't in $SOURCE"
done

# Copy the stored functions and procedures (such as LEVENSHTEIN), without their definers, which
# would need privileges that RDS doesn't grant
# shellcheck disable=SC2016 # The backticks are SQL identifier quotes
if ! mariadb-dump --defaults-extra-file="$options_file" --routines --no-create-info --no-data \
        --no-create-db --skip-triggers --skip-comments "$SOURCE" \
        | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//' \
        | sql "$DESTINATION"; then
    failed+=("(stored functions and procedures)")
fi

if (( ${#failed[@]} > 0 )); then
    echo "Failed to copy: ${failed[*]}" >&2
    exit 1
fi
