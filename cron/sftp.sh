#!/usr/bin/expect

set filename [lindex $argv 0]
set output_filename [lindex $argv 1]

spawn sftp {LIS_FTP_USERNAME}@sftp.dlas.virginia.gov:/CSV221/csv221/$filename $output_filename
expect "password"
send "{LIS_FTP_PASSWORD}\n"
interact
