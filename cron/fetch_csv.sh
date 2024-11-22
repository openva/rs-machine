#!/bin/bash

set -x

filename="$1"
output_filename="$2"

curl -o "$output_filename" https://lis.blob.core.windows.net/lisfiles/20{SESSION_LIS_ID}/"$filename"
