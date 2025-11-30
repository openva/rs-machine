<?php

/*
 * Select bills from this session that have fiscal impact statements, but no bill notes, and a
 * last-week view count that's above 5. Limit it to 10 at a go.
 */
$sql = 'SELECT
            bills.id,
            bills.number,
            fiscal_impact_statements.pdf_url,
            fiscal_impact_statements.id AS fis_id,
                (SELECT COUNT(*)
                FROM bills_views
                WHERE bill_id=bills.id AND
                date >= CURDATE() - INTERVAL 7 DAY) AS views
        FROM bills
        LEFT JOIN fiscal_impact_statements
            ON bills.id = fiscal_impact_statements.bill_id
        WHERE
            bills.session_id = ' . SESSION_ID . ' AND
            fiscal_impact_statements.pdf_url IS NOT NULL AND
            bills.notes IS NULL
        HAVING views >= 5
        ORDER BY views DESC
        LIMIT 10';
$result = mysqli_query($GLOBALS['db'], $sql);
if (mysqli_num_rows($result) > 0) {
    $bills = mysqli_fetch_all($result, MYSQLI_ASSOC);
}

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
        $log->put('The fiscal impact statement for ' . $bill['number'] . ' (' . urlencode($url)
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
        'model' => 'gpt-4o',
        'messages' => [
            [
                "role" => "system",
                "content" => "What does this Fiscal Impact Statement say that this legislation will
                    cost? Please provide the answer in a single paragraph, without using numbered
                    lists or bullet points."
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
    curl_close($ch);

    if (!$response) {
        die("Failed to get a response from OpenAI");
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['choices'][0]['message']['content'])) {
        $log->put('Error: OpenAI could summarie ' . $bill['number'], 4);
        continue;
    }
    $summary = $responseData['choices'][0]['message']['content'];

    // Link to the fiscal impact statement
    $summary = preg_replace(
        '/fiscal impact statement/i',
        '<a href="' . $url . '">fiscal impact statement</a>',
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
        $log->put('Added a fiscal impact summary for ' . $bill['number'] . '.', 3);
    }

    /*
     * Clean up temporary files
     */
    unlink($tmpPdfFile);
    unlink($tmpTxtFile);
}
