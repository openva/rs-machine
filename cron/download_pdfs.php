<?php

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

###
# Download PDFs of legislation
#
# PURPOSE
# Downloads PDFs from the legislature's website to store locally.
#
###

if (!class_exists('\Aws\S3\S3Client')) {
    include_once __DIR__ . '/../includes/vendor/autoload.php';
}

/*
 * Define the directory where the PDFs will be stored.
 */
$s3_bucket = 'downloads.richmondsunlight.com';
$s3_prefix = 'bills/' . SESSION_YEAR . '/';

if (!isset($log) || !($log instanceof Log)) {
    $log = new Log();
}

try {
    $s3_client = new S3Client([
        'version' => 'latest',
        'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
    ]);
} catch (Exception $e) {
    $log->put('Could not initialize S3 client: ' . $e->getMessage(), 5);
    return;
}

$mirrored = [];
try {
    $params = [
        'Bucket' => $s3_bucket,
        'Prefix' => $s3_prefix,
    ];

    do {
        $response = $s3_client->listObjectsV2($params);

        if (!empty($response['Contents'])) {
            foreach ($response['Contents'] as $object) {
                if (!isset($object['Key'])) {
                    continue;
                }
                $key = $object['Key'];
                if (strtolower(substr($key, -4)) !== '.pdf') {
                    continue;
                }
                $mirrored[] = strtolower(basename($key, '.pdf'));
            }
        }

        if (!empty($response['IsTruncated']) && !empty($response['NextContinuationToken'])) {
            $params['ContinuationToken'] = $response['NextContinuationToken'];
        } else {
            break;
        }
    } while (true);
} catch (AwsException $e) {
    $log->put('Could not list existing PDFs in S3: ' . $e->getAwsErrorMessage(), 5);
    return;
}

$mirrored = array_values(array_unique($mirrored));

/*
 * Get a list of every bill. This isn't necessarily the plain number (e.g., HB1), but may include
 * the revision number (e.g., HB1H3), which is why we get it from the bill text table.
 */
$sql = 'SELECT bills_full_text.number
		FROM bills_full_text
		LEFT JOIN bills
		    ON bills_full_text.bill_id = bills.id
		WHERE bills.session_id = ' . SESSION_ID;
$result = mysqli_query($GLOBALS['db'], $sql);
$bills = array();
while ($tmp = mysqli_fetch_array($result)) {
    $bills[] = strtolower($tmp['number']);
}

/*
 * Reduce our list of bills to only those that we don't have mirrored as PDFs.
 */
$bills = array_diff($bills, $mirrored);

/*
 * Iterate through the bills and retrieve each one.
 */
$import = new Import($log);

foreach ($bills as $bill) {
    $bill = trim($bill);
    if ($bill === '') {
        continue;
    }

    $document_number = strtoupper($bill);
    $key = $s3_prefix . strtolower($bill) . '.pdf';

    $import->bill_number = $document_number;
    $import->document_number = null;
    $import->lis_session_id = SESSION_LIS_ID;

    $binary = $import->get_bill_pdf_api();
    if ($binary === false || trim($binary) === '') {
        $log->put('Could not retrieve PDF for ' . $document_number . '.', 5);
        continue;
    }

    try {
        $s3_client->putObject([
            'Bucket' => $s3_bucket,
            'Key' => $key,
            'Body' => $binary,
            'ACL' => 'public-read',
            'ContentType' => 'application/pdf',
            'CacheControl' => 'public, max-age=86400',
        ]);
        $log->put('Retrieved PDF for ' . $document_number . ' and stored it at s3://'
            . $s3_bucket . '/' . $key . '.', 2);

        // Record the URL in the database with error handling.
        try {
            $pdfUrl = 'https://' . $s3_bucket . '/' . $key;
            $escapedUrl = mysqli_real_escape_string($GLOBALS['db'], $pdfUrl);
            $escapedNumber = mysqli_real_escape_string($GLOBALS['db'], $bill);
            $sql = 'UPDATE bills_full_text
                    SET pdf_url = "' . $escapedUrl . '"
                    WHERE number = "' . $escapedNumber . '"';
            if (!mysqli_query($GLOBALS['db'], $sql)) {
                throw new Exception('MySQL error: ' . mysqli_error($GLOBALS['db']));
            }
        } catch (Exception $dbException) {
            $log->put('Warning: could not store PDF URL for ' . $document_number
                . ': ' . $dbException->getMessage(), 4);
        }

    } catch (AwsException $e) {
        $log->put('Could not upload PDF for ' . $document_number . ' to S3: '
            . $e->getAwsErrorMessage(), 5);
    }
}
