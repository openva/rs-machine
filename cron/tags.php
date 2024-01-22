<?php

/*
 * Instantiate the logging class
 */
$log = new Log();

/*
 * Get a list of some bills that lack tags
 */
$sql = 'SELECT bills.id, bills.catch_line, bills.summary
        FROM bills
        LEFT JOIN tags
        ON bills.id=tags.bill_id
        WHERE
            session_id = ' . SESSION_ID . ' AND
            tags.bill_id IS NULL AND
            summary IS NOT NULL
        ORDER BY
            bills.date_introduced DESC
        LIMIT 10';
$stmt = $GLOBALS['dbh']->prepare($sql);
$stmt->execute();
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($bills) == 0)
{
    return;
}

foreach ($bills as &$bill) {
    $bill['summary'] = strip_tags($bill['summary']);
}

/*
 * Submit each bill to OpenAI
 */
$api_key = OPENAI_KEY;
$endpoint = 'https://api.openai.com/v1/chat/completions';

$role = 'You are a helpful assistant who generates tags to describe legislation. Given ' .
    'the title and description of a bill, you generate 1–6 tags that capture the purpose ' .
    'and impact of a bill. Each tag is 1–2 words long, and use simple English. For example, ' .
    'a bill about the regulation of automatic firearms might be tagged include "guns" and ' .
    '"weapon." A bill about the availability of birth control might be tagged "abortion," ' .
    '"reproductive rights," and "medicine." Provide ONLY tags in response to the query, ' .
    'separated by commas, in lowercase.';

/*
 * Create an initial connection to the endpoint, to be reused on each loop
 */
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key
]);

foreach ($bills as $bill) {
    $prompt = $bill['catch_line'] . "\n\n" . $bill['summary'];

    $data = [
        'model' => 'gpt-4-1106-preview',
        'messages' => [
            ['role' => 'system', 'content' => $role],
            ['role' => 'user', 'content' => 'Please provide tags for the following bill: ' . $prompt]
        ]
    ];

    /*
     * Submit query
     */
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        $log->put('ERROR: Could not query OpenAI API, with this failure: ' . curl_error($ch), 3);
    }

    /*
     * Use the response
     */
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        $generated_text = strtolower($result['choices'][0]['message']['content']);
        $tags = explode(', ', $generated_text);

        foreach ($tags as $tag) {
            if ($tag == 'legislation') {
                continue;
            }

            $sql = 'INSERT INTO tags
                    SET bill_id=:bill_id,
                    tag=:tag,
                    ip="127.0.0.1",
                    date_created=now()';
            $stmt = $GLOBALS['dbh']->prepare($sql);
            $stmt->bindParam(":bill_id", $bill['id'], PDO::PARAM_INT);
            $stmt->bindParam(":tag", $tag, PDO::PARAM_STR);
            try {
                $stmt->execute();
            } catch (PDOException $e) {
                $log->put('Failed while adding auto-generated tag ($tag): ' . $e->getMessage(), 2);
                continue;
            }
        } // end foreach tags

        $log->put('Auto-generated tags: ' . implode(', ', $tags), 2);
    }
} // end foreach bills

curl_close($ch);
