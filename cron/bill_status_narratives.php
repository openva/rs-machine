<?php

###
# Summarize Bill Status Narratives
#
# PURPOSE
# Builds a prompt from bill metadata + status history, sends it to OpenAI for a narrative
# summary, and stores the result.
#
###

# INCLUDES
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

$dbh = new Database();
$db = $dbh->connect_mysqli();
$log = new Log();

if (!defined('OPENAI_KEY') || empty(OPENAI_KEY)) {
    $log->put('OPENAI_KEY is not configured—cannot generate bill narratives.', 6);
    return;
}

// Configuration for OpenAI request
$model = 'gpt-4o';
$temperature = 0.3;
$max_tokens = 350;

// Only summarize last year's bills
$session_year=$session_year - 1;

if ($session_year >= 2022 && $session_year < 2026) {
    $house_majority = 'Democratic';
    $senate_majority = 'Democratic';
    $governor_party = 'Republican';
    $lg_party = 'Republican';
} elseif ($session_year >= 2026 && $session_year <= 2027) {
    $house_majority = 'Democratic';
    $senate_majority = 'Democratic';
    $governor_party = 'Democratic';
    $lg_party = 'Democratic';
} elseif ($session_year > 2027) {
    $log->put('Session year is outside of configuration, cannot summarize bill histories.', 5);
    return;
}

// Get crossover date for the session
$crossover_date = get_crossover_date($db, $session_year);

/*
 * Available placeholders:
 * - {{bill_number}}: Bill number (e.g., HB1234)
 * - {{catch_line}}: Bill catch line
 * - {{session_year}}: Session year (e.g., 2025)
 * - {{status_history}}: Line-delimited status history with dates
 * - {{sponsor_party}}: Chief patron's party affiliation
 * - {{crossover_date}}: Date of crossover for the session
 * - {{house_majority}}: The majority party in the House
 * - {{senate_majority}}: The majority party in the Senate
 * - {{governor_party}}: The governor's party affiliation
 * - {{lg_party}}: The lieutenant governor's party affiliation
 */
$prompt_template = <<<'PROMPT'
Please summarize the following legislative history in no more than three sentences, such that
a layperson can understand the journey of the legislation. Note if the various votes were
unanimous or near-unanimous, or if they were narrow, note that, too. If votes were unanimous
or near-unanimous, there is no need to specify the vote tally. Most important is the outcome:
if the bill was killed in subcommittee, if it failed to get a majority vote on the floor, if it
was vetoed by the governor, or if it passed into law.

A few procedural notes that may or may not inform the summary:

- The date of "crossover" (by when bills must pass their chamber in order to be considered in
  the other chamber) was Feb. 7, 2024.
- Bills are often referred to the Rules committee in order to kill them without having to
  record a vote—the Rules committee simply never votes on them. If a bill isn't about the rules
  of the legislature, and was sent to Rules, and failed because it did not receive a vote there,
  that was probably intentional.
- The House was controlled by Democrats, the Senate was controlled by Democrats, the governor
  was a Republican, the lieutenant governor (who presides over the Senate and casts
  tie-breaking votes) was a Republican, and the bill was introduced by a {{sponsor_party}}.

Do not mention partisanship unless it is relevant to the outcome of the bill. Do not specify
the bill's number—that is not relevant to the summary. Simply describe it as "the bill,"
"the legislation," or "this." There is no need to describe the bill's subject matter, either,
unless it's relevant to the outcome. There is no need to provide specific dates unless it’s
important to describing the bill’s journey.

{{bill_number}}: {{catch_line}}
{{status_history}}
PROMPT;

if (stripos($prompt_template, 'TODO:') === 0) {
    $log->put('Prompt template not configured for bill status narratives. Skipping.', 5);
    return;
}

$bills = get_bill_candidates($db, $session_year);
if (empty($bills)) {
    $log->put('No bills need narratives right now.', 3);
    return;
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: ' . 'Bearer ' . OPENAI_KEY,
]);

foreach ($bills as $bill) {
    $history = get_bill_status_history($db, (int)$bill['id'], (int)$bill['session_id']);
    if (empty($history)) {
        $log->put('No status history for ' . $bill['number'] . '; skipping narrative.', 4);
        continue;
    }

    $prompt = build_prompt_from_template(
        $prompt_template,
        $bill,
        $history,
        $crossover_date,
        $house_majority,
        $senate_majority,
        $governor_party,
        $lg_party
    );

    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You summarize the legislative history of Virginia bills clearly and concisely.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    if ($response === false) {
        $log->put('OpenAI request failed for ' . $bill['number'] . ': ' . curl_error($ch), 6);
        continue;
    }

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (empty($content)) {
        $log->put('OpenAI returned no content for ' . $bill['number'] . '.', 5);
        continue;
    }

    $narrative = trim($content);

    store_bill_narrative($db, $bill, $narrative, $log);
}

/**
 * Get the crossover date for a given session year.
 */
function get_crossover_date(mysqli $db, int $session_year): ?string
{
    $sql = 'SELECT crossover
            FROM sessions
            WHERE year = ' . $session_year . ' AND crossover IS NOT NULL
            LIMIT 1';

    $result = mysqli_query($db, $sql);
    if ($result === false || mysqli_num_rows($result) === 0) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    return $row['crossover'] ?? null;
}

/**
 * Select bills in the current session that do not yet have a current narrative.
 */
function get_bill_candidates(mysqli $db, int $session_year): array
{
    $sql = 'SELECT
                bills.id,
                bills.number,
                bills.catch_line,
                bills.status,
                bills.session_id,
                sessions.year AS session_year,
                CASE terms.party
                    WHEN "R" THEN "Republican"
                    WHEN "D" THEN "Democrat"
                    ELSE terms.party
                END AS sponsor_party
            FROM bills
            LEFT JOIN sessions
                ON bills.session_id = sessions.id
            LEFT JOIN terms
            	ON bills.chief_patron_id = terms.person_id
            WHERE
		sessions.year = ' . $session_year . ' AND
		sessions.suffix IS NULL AND
                EXISTS (
                    SELECT 1
                    FROM bills_status
                    WHERE bill_id = bills.id AND session_id = bills.session_id
                ) AND
                NOT EXISTS (
                    SELECT 1
                    FROM bills_status_narratives
                    WHERE bill_id = bills.id AND current = "y"
                )
            ORDER BY bills.interestingness DESC
            LIMIT 10';
    $result = mysqli_query($db, $sql);
    if ($result === false || mysqli_num_rows($result) === 0) {
        return [];
    }

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/**
 * Retrieve status history for a bill.
 */
function get_bill_status_history(mysqli $db, int $bill_id, int $session_id): array
{
    $sql = 'SELECT status, date
            FROM bills_status
            WHERE bill_id = ' . $bill_id . ' AND session_id = ' . $session_id . '
            ORDER BY date ASC, id ASC';

    $result = mysqli_query($db, $sql);
    if ($result === false || mysqli_num_rows($result) === 0) {
        return [];
    }

    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/**
 * Replace placeholders in the prompt template.
 */
function build_prompt_from_template(
    string $template,
    array $bill,
    array $history,
    ?string $crossover_date,
    string $house_majority,
    string $senate_majority,
    string $governor_party,
    string $lg_party
): string
{
    $status_lines = [];
    foreach ($history as $status) {
        $status_text = $status['status'];
        $status_lines[] = $status['date'] . ': ' . $status_text;
    }

    $latest_status = end($history);
    $current_status = $latest_status['status'];

    $replacements = [
        '{{bill_number}}' => strtoupper($bill['number']),
        '{{catch_line}}' => $bill['catch_line'] ?? '',
        '{{session_year}}' => $bill['session_year'] ?? '',
        '{{sponsor_party}}' => $bill['sponsor_party'] ?? '',
        '{{current_status}}' => $current_status,
        '{{status_history}}' => implode("\n", $status_lines),
        '{{crossover_date}}' => $crossover_date ?? '',
        '{{house_majority}}' => $house_majority,
        '{{senate_majority}}' => $senate_majority,
        '{{governor_party}}' => $governor_party,
        '{{lg_party}}' => $lg_party,
    ];

    return strtr($template, $replacements);
}

/**
 * Store the generated narrative, ensuring only one current narrative per bill.
 */
function store_bill_narrative(mysqli $db, array $bill, string $narrative, Log $log): void
{
    $bill_id = (int)$bill['id'];
    $session_id = (int)$bill['session_id'];

    $escaped_narrative = mysqli_real_escape_string($db, $narrative);

    $sql = 'UPDATE bills_status_narratives
            SET current = "n"
            WHERE bill_id = ' . $bill_id . ' AND current = "y"';
    mysqli_query($db, $sql);

    $sql = 'INSERT INTO bills_status_narratives
            SET
                bill_id = ' . $bill_id . ',
                session_id = ' . $session_id . ',
                text = "' . $escaped_narrative . '",
                current = "y",
                date_created = NOW()';
    $result = mysqli_query($db, $sql);

    if ($result === false) {
        $log->put('Error: could not store narrative for ' . $bill['number'] . ': '
            . mysqli_error($db), 5);
    } else {
        $log->put('Stored bill status narrative for ' . $bill['number'] . '.', 3);
    }
}
