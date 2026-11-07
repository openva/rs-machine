#!/bin/bash
#
# Download a file from LIS. On any failure, exit non-zero and leave the previous copy in place.

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
if ! curl --fail --silent --show-error -o "$tmp_file" "$url"; then
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
