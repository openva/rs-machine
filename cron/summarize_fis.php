<?php

use RsMachine\ImpactStatements;

/*
 * Select up to 10 fiscal impact statements to summarize, from this session's bills with a
 * last-week view count of at least 2. Only a bill's newest statement from each agency is shown on
 * the website, so only those are summarized. That takes all of a bill's statements, not just the
 * unsummarized ones, so the query gets every statement for each bill that has any unsummarized.
 */
$sql = 'SELECT
            bills.id AS bill_id,
            bills.number,
            fiscal_impact_statements.id,
            fiscal_impact_statements.lis_id,
            fiscal_impact_statements.pdf_url,
            fiscal_impact_statements.summary,
                (SELECT COUNT(*)
                FROM bills_views
                WHERE bill_id=bills.id AND
                date >= CURDATE() - INTERVAL 7 DAY) AS views
        FROM bills
        JOIN fiscal_impact_statements
            ON bills.id = fiscal_impact_statements.bill_id
        WHERE
            bills.session_id = ' . SESSION_ID . ' AND
            EXISTS (
                SELECT 1
                FROM fiscal_impact_statements AS unsummarized
                WHERE
                    unsummarized.bill_id = bills.id AND
                    unsummarized.pdf_url IS NOT NULL AND
                    unsummarized.summary IS NULL
            )
        HAVING views >= 2';
$result = mysqli_query($GLOBALS['db'], $sql);
$bills = [];
foreach (ImpactStatements::toSummarize(mysqli_fetch_all($result, MYSQLI_ASSOC), 10) as $statement) {
    $statement['fis_id'] = $statement['id'];
    $bills[] = $statement;
}

// Initalize variable for logging
$fis_added = 0;

foreach ($bills as $bill) {
    // Sometimes we're not getting bill numbers, unclear why
    if (!isset($bill['number']) || empty($bill['number'])) {
        continue;
    }

    /*
     * Step 1: Download the PDF
     */
    $pdfContent = file_get_contents($bill['pdf_url']);
    if (!$pdfContent) {
        die('Failed to download PDF');
    }

    /*
     * Save the PDF to a temporary file
     */
    $tmpPdfFile = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($tmpPdfFile, $pdfContent);

    /*
     * Step 2: Convert PDF to Text
     */
    $tmpTxtFile = $tmpPdfFile . '.txt';
    exec("pdftotext $tmpPdfFile $tmpTxtFile");

    /*
     * Read the converted text
     */
    $text = file_get_contents($tmpTxtFile);
    if (!$text) {
        $log->put('The fiscal impact statement for ' . $bill['number'] . ' (' . urlencode($bill['pdf_url'])
            . ') doesn’t appear to be a PDF. Skipping. ', 3);
        continue;
    }

    /*
     * Step 3: Submit to OpenAI for Summarization
     */
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-5-mini',
        'messages' => [
            [
                "role" => "system",
                "content" => "What does this Fiscal Impact Statement say that this legislation will
                    cost? Please provide the answer in a single paragraph, without using numbered
                    lists or bullet points. Please specify which agency, department, or
                    organization created the fiscal impact statement, which is usually the the
                    Department of Planning and Budget, but may come from others, such as the
                    Virginia Criminal Sentencing Commission."
            ],
            [
                "role" => "user",
                "content" => $text
            ]
        ],
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_KEY,
    ]);

    $response = curl_exec($ch);

    if (!$response) {
        die("Failed to get a response from OpenAI");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['choices'][0]['message']['content'])) {
        $log->put('Error: OpenAI couldn’t summarize ' . $bill['number'], 4);
        continue;
    }
    $summary = $responseData['choices'][0]['message']['content'];

    // Link to the fiscal impact statement
    $summary = preg_replace(
        '/fiscal impact statement/i',
        '<a href="' . $bill['pdf_url'] . '">fiscal impact statement</a>',
        $summary
    );

    // Add a disclaimer
    $summary = '<p>' . $summary . '</p>
        <p class="openai">Fiscal impact statement automatically summarized by OpenAI.</p>';

    /*
     * Step 4: Save the Summary
     */
    $sql = 'UPDATE fiscal_impact_statements
            SET summary = "' . mysqli_real_escape_string($GLOBALS['db'], $summary) . '"
            WHERE id = ' . $bill['fis_id'];

    $result = mysqli_query($GLOBALS['db'], $sql);
    if ($result === false) {
        $log->put('Error: Adding a fiscal impact summary-summary for ' . $bill['number'] . ' failed: '
            . mysqli_error($GLOBALS['db']), 4);
    } else {
        $fis_added++;
        $log->put('Added a fiscal impact summary for ' . $bill['number'] . '.', 2);
    }

    /*
     * Clean up temporary files
     */
    unlink($tmpPdfFile);
    unlink($tmpTxtFile);
}

if ($fis_added) {
    $log->put('Added ' . $fis_added . ' fiscal impact statement summaries.', 3);
}
