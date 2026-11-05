<?php

/*
 * Store again how each legislator voted in a session's votes, from LIS.
 *
 * Until November 2026, representatives.id held the ID of a person's first term, not the person's
 * ID, for anyone who took office after people and terms were created. vote.php recorded votes by
 * representatives.id, so those legislators' votes were stored under term IDs, some of which are
 * other legislators' person IDs. Where two legislators in a vote shared an ID, one's vote
 * overwrote the other's. This replaces each vote's legislators' votes with LIS's, by person ID.
 *
 * A vote any of whose voters can't be identified is left alone, and reported.
 *
 * Usage: php utilities/refetch_votes.php <session ID> [--dry-run] [--from-file <LIS response>]
 *   --dry-run    Report what would change, without changing it.
 *   --from-file  Read LIS's list of the session's votes (GetVoteByIdAsync) from a file.
 */

use RsMachine\SessionMembers;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$dry_run = false;
$from_file = null;
$session_id = 0;
while ($arguments !== []) {
    $argument = array_shift($arguments);
    if ($argument === '--dry-run') {
        $dry_run = true;
    } elseif ($argument === '--from-file') {
        $from_file = array_shift($arguments);
    } else {
        $session_id = (int) $argument;
    }
}
if ($session_id === 0 || ($from_file !== null && !is_readable($from_file))) {
    fwrite(STDERR, "Usage: php utilities/refetch_votes.php <session ID> [--dry-run] [--from-file <file>]\n");
    exit(1);
}

$dbh = (new Database())->connect();
if (!$dbh instanceof PDO) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
 * LIS's list of the session's votes, with each legislator's vote.
 */
if ($from_file !== null) {
    $response = file_get_contents($from_file);
} else {
    $session = $dbh->prepare('SELECT lis_id FROM sessions WHERE id = :id');
    $session->execute([':id' => $session_id]);
    $lis_id = $session->fetchColumn();
    if (!$lis_id) {
        fwrite(STDERR, 'Session ' . $session_id . " has no LIS ID.\n");
        exit(1);
    }

    $ch = curl_init('https://lis.virginia.gov/Vote/api/GetVoteByIdAsync?sessionCode=20' . $lis_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['WebAPIKey: ' . LIS_KEY, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status !== 200) {
        fwrite(STDERR, 'Could not retrieve the votes from LIS (HTTP ' . $status . ").\n");
        exit(1);
    }
}
$lis_votes = json_decode((string) $response, true)['Votes'] ?? null;
if (!is_array($lis_votes) || $lis_votes === []) {
    fwrite(STDERR, "LIS returned no votes.\n");
    exit(1);
}

/*
 * Our record of the session's votes, and who served during it.
 */
$stmt = $dbh->prepare('SELECT lis_id, id FROM votes WHERE session_id = :session_id');
$stmt->execute([':session_id' => $session_id]);
$our_votes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$members = SessionMembers::lookUp($dbh, $session_id);

$stored = $dbh->prepare('SELECT representative_id, vote FROM representatives_votes WHERE vote_id = :vote_id');
$delete = $dbh->prepare('DELETE FROM representatives_votes WHERE vote_id = :vote_id');
$insert = $dbh->prepare('INSERT INTO representatives_votes (representative_id, vote, vote_id, date_created)
    VALUES (:representative_id, :vote, :vote_id, NOW())');

$counts = ['matched' => 0, 'unchanged' => 0, 'changed' => 0, 'unidentified' => 0];
$unidentified = [];
foreach ($lis_votes as $lis_vote) {
    $vote_id = $our_votes[(string) ($lis_vote['VoteID'] ?? '')] ?? null;
    if ($vote_id === null || empty($lis_vote['VoteMember'])) {
        continue;
    }
    $counts['matched']++;
    $chamber = ($lis_vote['ChamberCode'] ?? '') === 'S' ? 'senate' : 'house';

    // How each legislator voted, by person ID.
    $votes = [];
    $complete = true;
    foreach ($lis_vote['VoteMember'] as $member) {
        $member_id = SessionMembers::memberId($chamber, (int) preg_replace('/\D/', '', (string) $member['MemberNumber']));
        if (!isset($members[$member_id])) {
            $unidentified[$member_id] = true;
            $complete = false;
            continue;
        }
        $votes[$members[$member_id]] = (string) $member['ResponseCode'];
    }
    if (!$complete) {
        $counts['unidentified']++;
        continue;
    }

    $stored->execute([':vote_id' => $vote_id]);
    $previous = array_map('strval', $stored->fetchAll(PDO::FETCH_KEY_PAIR));
    ksort($previous);
    ksort($votes);
    if ($previous === $votes) {
        $counts['unchanged']++;
        continue;
    }

    $counts['changed']++;
    if ($dry_run) {
        continue;
    }
    $dbh->beginTransaction();
    try {
        $delete->execute([':vote_id' => $vote_id]);
        foreach ($votes as $person_id => $response_code) {
            $insert->execute([':representative_id' => $person_id, ':vote' => $response_code, ':vote_id' => $vote_id]);
        }
        $dbh->commit();
    } catch (Throwable $e) {
        $dbh->rollBack();
        throw $e;
    }
}

echo 'Session ' . $session_id . ': ' . count($lis_votes) . ' votes from LIS, ' . $counts['matched']
    . ' of them ours; ' . $counts['unchanged'] . ' already right, ' . $counts['changed']
    . ($dry_run ? ' would be corrected' : ' corrected') . ', ' . $counts['unidentified']
    . " left alone because a voter couldn't be identified.\n";
if ($unidentified !== []) {
    echo 'Member IDs that match no one who served this session: '
        . implode(', ', array_keys($unidentified)) . "\n";
}
