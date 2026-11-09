#!/bin/bash
#
# Download a file from LIS. On any failure, exit non-zero and leave the previous copy in place.
# Exit 4 when LIS has no such file for the session (a 404), which isn't an error: LIS publishes
# each session's files only once it has data for them, so the runner skips the task instead.

set -x

filename="$1"
output_filename="$2"

# The LIS session code (e.g. 20261) may be given as a third argument, which run.php does so that
# it can fetch another session's files (see --session). Otherwise, the deploy replaces this
# placeholder with the current session's LIS ID.
default_session_code="20{SESSION_LIS_ID}"
session_code="${3:-$default_session_code}"
url="https://lis.blob.core.windows.net/lisfiles/${session_code}/${filename}"
# (Checking for a brace, not the placeholder itself, which the deploy would replace here too.)
if [[ "$url" == *"{"* ]]; then
    echo "The LIS session ID was never filled in, so ${filename} can't be fetched." >&2
    exit 1
fi

# Download to a temporary file, failing on HTTP errors, so an error page never replaces good data.
tmp_file="$(mktemp "${output_filename}.XXXXXX")" || exit 1
if ! http_status="$(curl --silent --show-error -o "$tmp_file" -w '%{http_code}' "$url")"; then
    rm -f "$tmp_file"
    exit 1
fi

if [[ "$http_status" == "404" ]]; then
    echo "LIS has no ${filename} for session ${session_code}." >&2
    rm -f "$tmp_file"
    exit 4
fi

if [[ "$http_status" -ge 400 ]]; then
    echo "LIS returned HTTP ${http_status} for ${filename}." >&2
    rm -f "$tmp_file"
    exit 1
fi

if [[ ! -s "$tmp_file" ]]; then
    echo "${filename} was empty." >&2
    rm -f "$tmp_file"
    exit 1
fi

chmod 644 "$tmp_file"
mv "$tmp_file" "$output_filename"
