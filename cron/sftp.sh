#!/usr/bin/expect

set filename [lindex $argv 0]
set output_filename [lindex $argv 1]

spawn sftp {USERNAME}@sftp.dlas.virginia.gov:/CSV221/csv221/$filename $output_filename
expect "password"
send "{PASSWORD}\n"
interact
