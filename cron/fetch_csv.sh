#!/bin/bash
#
# Download a file from LIS. On any failure, exit non-zero and leave the previous copy in place.

set -x

filename="$1"
output_filename="$2"

# The deploy replaces this placeholder with the session's LIS ID.
url="https://lis.blob.core.windows.net/lisfiles/20{SESSION_LIS_ID}/${filename}"
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
