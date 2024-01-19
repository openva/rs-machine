<?php

###
# Download PDFs of legislation
#
# PURPOSE
# Downloads PDFs from the legislature's website to store locally.
#
###

/*
 * Define the directory where the PDFs will be stored.
 */
$dir = '../downloads/bills/' . SESSION_YEAR . '/pdf/';

if (file_exists($dir) === false) {
    mkdir($dir);
}

/*
 * Get a list of every PDF that's in this directory.
 */
$mirrored = scandir($dir);

/*
 * Eliminate the current and parent directories.
 */
unset($mirrored[0]);
unset($mirrored[1]);
$mirrored = array_values($mirrored);

/*
 * Remove the ".pdf" suffix.
 */
foreach ($mirrored as &$pdf) {
    $pdf = str_replace($mirrored, '.pdf', '');
}

/*
 * Get a list of every bill. This isn't necessarily the plain number (e.g., HB1), but may include
 * the revision number (e.g., HB1H3), which is why we get it from the bill text table.
 */
$sql = 'SELECT bills_full_text.number
		FROM bills_full_text
		LEFT JOIN bills
		ON bills_full_text.bill_id = bills.id
		WHERE bills.session_id = ' . SESSION_ID;
$result = mysql_query($sql);
$bills = array();
while ($tmp = mysql_fetch_array($result)) {
    $bills[] = strtolower($tmp['number']);
}

/*
 * Reduce our list of bills to only those that we don't have mirrored as PDFs.
 */
$bills = array_diff($bills, $mirrored);

/*
 * Iterate through the bills and retrieve each one.
 */
foreach ($bills as $bill) {
    $pdf_url = 'http://lis.virginia.gov/cgi-bin/legp604.exe?' . SESSION_LIS_ID . '+ful+'
        . strtoupper($bill) . '+pdf';
    $pdf_contents = file_get_contents($pdf_url);
    file_put_contents($dir . $bill . '.pdf', $pdf_contents);
}
