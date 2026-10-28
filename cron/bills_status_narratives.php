<?php

###
# Summarize Bill Status Narratives
#
# PURPOSE
# Builds a prompt from bill metadata + status history, sends it to OpenAI for a narrative
# summary, and stores the result.
#
###

// This temp thing is about dealing with a weird database connetion bug
$dbh_temp = new Database();
$db = $dbh_temp->connect_mysqli();
$log = new Log();

if (!defined('OPENAI_KEY') || empty(OPENAI_KEY)) {
    $log->put('OPENAI_KEY is not configured—cannot generate bill narratives.', 6);
    return;
}

/*
 * Configuration for OpenAI request. gpt-4o is retired on 2027-01-14, and OpenAI names gpt-6-luna
 * as its replacement. That's a reasoning model: it doesn't take a temperature, and its reasoning
 * counts against the completion limit, which therefore needs headroom beyond the ~150 tokens of
 * the narrative itself.
 */
$model = 'gpt-6-luna';
$reasoning_effort = 'low';
$max_completion_tokens = 2000;

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
 * Whether the session is underway, adjourned with the governor's actions pending, or over. This
 * decides how the bill's outcome is described, and which existing narratives are out of date.
 */
$session_phase = \RsMachine\SessionPhase::determine(
    get_session_end_date($db, $session_year),
    new DateTimeImmutable('today')
);

$session_status_text = [
    \RsMachine\SessionPhase::IN_SESSION => <<<'TEXT'
The session is still underway, so the bill's fate may not be decided yet—a bill can be revived
later in the session. Don't describe a bill that hasn't passed in the past tense (e.g. "the bill
did not pass into law"). Instead say something like "the bill has not passed" or "the bill is
effectively dead."
TEXT,
    \RsMachine\SessionPhase::ADJOURNED => <<<'TEXT'
The session has adjourned, so a bill that did not pass both chambers is dead; say so plainly, in
the past tense. But the governor may still sign, amend, or veto a bill that passed both chambers,
and the General Assembly reconvenes on {{reconvenes}} to consider the governor's amendments and
vetoes. If such a bill's history doesn't yet show its final outcome, say that it awaits that.
TEXT,
    \RsMachine\SessionPhase::FINAL => <<<'TEXT'
The session is over, including the reconvened session at which the General Assembly considered
the governor's amendments and vetoes, so every bill's outcome is final. Describe the bill in the
past tense, and state plainly whether it became law. A bill became law if the governor signed it,
if it became law without the governor's signature, or if a veto was overridden. Every other bill
is dead—including one that was left in committee, was never voted on, failed a vote, or was vetoed
without an override—and the summary should say so. A bill that was incorporated into another bill
did not pass on its own, so say that it was incorporated into the other bill rather than that it
died. The one exception is a bill that was continued to the next year's session, which is still
alive and should be described that way. A resolution doesn't become law, so for a resolution, say
whether it was agreed to.
TEXT,
][$session_phase->phase];

/*
 * Available placeholders:
 * - {{bill_number}}: Bill number (e.g., HB1234)
 * - {{catch_line}}: Bill catch line
 * - {{session_year}}: Session year (e.g., 2025)
 * - {{session_status}}: Whether the session is underway, adjourned, or over, and what follows
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
was vetoed by the governor, or if it passed into law. Today's date is {{current_date}}, and this
bill is from the {{session_year}} session.

{{session_status}}

A few procedural notes that may or may not inform the summary:

- The date of "crossover" (by when bills must pass their chamber in order to be considered in
  the other chamber) was (or will be) {{crossover_date}}.
- Whether a vote was narrow depends on where it was taken. In a subcommittee, a margin of one
  vote is narrow. In a committee, a margin of two votes or fewer is narrow. On the floor of the
  House or the Senate, a margin of 5% or less of the votes cast is narrow (e.g. 52-48 in the
  House, or 21-19 in the Senate). Any wider margin is not narrow, so don't describe it that way.
- Bills are often referred to the Rules committee in order to kill them without having to
  record a vote—the Rules committee simply never votes on them. If a bill isn't about the rules
  of the legislature, and was sent to Rules, and failed because it did not receive a vote there,
  that was probably intentional.
- The House majority was {{house_majority}}, the Senate majority was {{senate_majority}}, the
  governor was {{governor_party}}, the lieutenant governor (who presides over the Senate and casts
  tie-breaking votes) was {{lg_party}}, and the bill was introduced by a {{sponsor_party}}.

Do not mention a legislator’s party unless it is relevant to the outcome of the bill. Do not
specify the bill's number—that is not relevant to the summary. Simply describe it as "the bill,"
"the legislation," or "this." DO NOT DESCRIBE THE BILL’S SUBJECT MATTER OR THE PURPOSE OF THE BILL.
There is no need to provide specific dates unless it’s important to describing the bill’s
journey.

{{bill_number}}: {{catch_line}}
{{status_history}}
PROMPT;

$prompt_template = strtr($prompt_template, [
    '{{session_status}}' => strtr($session_status_text, [
        '{{reconvenes}}' => $session_phase->reconvenes?->format('F j, Y') ?? 'a later date',
    ]),
]);

if (stripos($prompt_template, 'TODO:') === 0) {
    $log->put('Prompt template not configured for bill status narratives. Skipping.', 5);
    return;
}

$bills = get_bill_candidates($db, $session_year, $session_phase->staleBefore());
if (empty($bills)) {
    $log->put('No bills need narratives right now.', 1);
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
        'reasoning_effort' => $reasoning_effort,
        'max_completion_tokens' => $max_completion_tokens,
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    if ($response === false) {
        $log->put('Bill narratives OpenAI request failed for ' . $bill['number'] . ': ' . curl_error($ch), 6);
        continue;
    }

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (empty($content)) {
        if (isset($decoded['error'])) {
            $log->put('OpenAI returned an error for ' . $bill['number'] . ' bill narrative: '
                . ($decoded['error']['message'] ?? 'Unknown error'), 5);
        } elseif (($decoded['choices'][0]['finish_reason'] ?? null) === 'length') {
            $log->put('OpenAI ran out of tokens before writing the ' . $bill['number']
                . ' bill narrative; $max_completion_tokens may need raising.', 5);
        } else {
            $log->put('OpenAI returned no content for ' . $bill['number'] . ' bill narrative.', 5);
        }
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
            WHERE
                year = ' . $session_year . ' AND
                crossover IS NOT NULL
            LIMIT 1';

    $result = mysqli_query($db, $sql);
    if ($result === false || mysqli_num_rows($result) === 0) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    return $row['crossover'] ?? null;
}

/**
 * Get the adjournment date of a given year's regular session, or null if it isn't recorded.
 */
function get_session_end_date(mysqli $db, int $session_year): ?string
{
    $sql = 'SELECT date_ended
            FROM sessions
            WHERE
                year = ' . $session_year . ' AND
                suffix IS NULL
            LIMIT 1';

    $result = mysqli_query($db, $sql);
    if ($result === false || mysqli_num_rows($result) === 0) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    return $row['date_ended'] ?? null;
}

/**
 * Select bills in the session that do not yet have an up-to-date narrative, and have an
 * interestingness score of at least 20 (an admittedly arbitrary threshold, designed
 * to keep LLM costs down).
 *
 * A narrative is up to date if it's current (cleanup.php retires it when the bill's status
 * changes) and, when $stale_before is given, was written on or after that date. That's how each
 * narrative is rewritten once after the session adjourns, and once more when outcomes are final,
 * without rewriting any of them more than that.
 */
function get_bill_candidates(mysqli $db, int $session_year, ?string $stale_before = null): array
{
    $freshness = '';
    if ($stale_before !== null) {
        $freshness = ' AND date_created >= "' . mysqli_real_escape_string($db, $stale_before) . '"';
    }

    /*
     * The sponsor's party comes from a subquery rather than a join on terms, because a join
     * returns a bill once per term its patron has served, summarizing it repeatedly.
     */
    $sql = 'SELECT
                bills.id,
                bills.number,
                bills.catch_line,
                bills.status,
                bills.session_id,
                sessions.year AS session_year,
                CASE (
                    SELECT terms.party
                    FROM terms
                    WHERE
                        terms.person_id = bills.chief_patron_id AND
                        terms.date_started <= COALESCE(bills.date_introduced, sessions.date_started)
                    ORDER BY terms.date_started DESC
                    LIMIT 1
                )
                    WHEN "R" THEN "Republican"
                    WHEN "D" THEN "Democrat"
                    WHEN "I" THEN "independent"
                    ELSE "legislator"
                END AS sponsor_party
            FROM bills
            LEFT JOIN sessions
                ON bills.session_id = sessions.id
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
                    WHERE bill_id = bills.id AND current = "y"' . $freshness . '
                ) AND
                interestingness >= 20
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
            WHERE
                bill_id = ' . $bill_id . ' AND
                session_id = ' . $session_id . '
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
        '{{current_date}}' => date('Y-m-d'),
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
        $log->put('Error: could not store narrative for ' . $bill['session_year'] . '’s '
            . strtoupper($bill['number']) . ': '
            . mysqli_error($db), 5);
    } else {
        $log->put('Stored bill status narrative for ' . $bill['session_year'] . '’s '
            . strtoupper($bill['number']) . '.', 3);
    }
}
