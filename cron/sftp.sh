#!/usr/bin/expect

set filename [lindex $argv 0]
set output_filename [lindex $argv 1]

# Seriously, the path contains "CSV221," no matter hwhat the year or session
spawn sftp {LIS_FTP_USERNAME}@sftp.dlas.virginia.gov:/CSV221/csv{SESSION_LIS_ID}/$filename $output_filename
expect "password"
send "{LIS_FTP_PASSWORD}\n" 
expect eof
