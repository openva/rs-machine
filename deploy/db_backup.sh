#!/bin/bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"

settings_file="$repo_root/includes/settings.inc.php"
if [ ! -f "$settings_file" ]; then
    echo "Missing settings file: $settings_file" >&2
    exit 1
fi

eval "$(
    php -r "include '$settings_file';
        echo 'DB_NAME=' . escapeshellarg(MYSQL_DATABASE) . PHP_EOL;
        echo 'DB_HOST=' . escapeshellarg(PDO_SERVER) . PHP_EOL;
        echo 'DB_USER=' . escapeshellarg(PDO_USERNAME) . PHP_EOL;
        echo 'DB_PASS=' . escapeshellarg(PDO_PASSWORD) . PHP_EOL;"
)"

backup_dir="${HOME}/db_backups"
mkdir -p "$backup_dir"

timestamp="$(date +"%Y%m%d_%H%M%S")"
backup_file="${backup_dir}/rs-machine-${DB_NAME}-${timestamp}.sql.gz"

mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" \
    --single-transaction --quick --routines --triggers "$DB_NAME" | gzip > "$backup_file"

# Keep only the 3 most recent backups
backups=("$backup_dir"/rs-machine-"$DB_NAME"-*.sql.gz)
if [ -e "${backups[0]}" ]; then
    mapfile -t sorted < <(ls -1t "${backups[@]}")
    if [ "${#sorted[@]}" -gt 3 ]; then
        for old in "${sorted[@]:3}"; do
            rm -f -- "$old"
        done
    fi
fi
