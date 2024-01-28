<?php

require_once 'includes/settings.inc.php';
require_once 'vendor/autoload.php';

$import = new Import();

/*
 * Create our test data
 */
$names = [];
$names[] = array(
    'casual' => 'Sturtevant, Glen H.',
    'full' => 'Glen H. Sturtevant Jr.',
    'correct' => 'ghsturtevant');
$names[] = array(
    'casual' => 'McGuire, John',
    'full' => 'John McGuire III',
    'correct' => 'jmcguire');
$names[] = array(
    'casual' => 'Diggs, Danny',
    'full' => 'J.D. "Danny" Diggs',
    'correct' => 'jddiggs');
    $names[] = array(
        'casual' => 'Diggs, Danny',
        'full' => 'John Daniel "Danny" Diggs',
        'correct' => 'jddiggs');
$names[] = array(
    'casual' => 'O\'Quinn, Israel',
    'full' => 'Israel D. O\'Quinn',
    'correct' => 'idoquinn');
$names[] = array(
        'casual' => 'O’Quinn, Israel',
        'full' => 'Israel D. O’Quinn',
        'correct' => 'idoquinn');
$names[] = array(
        'casual' => 'Keys-Gamarra, Karen',
        'full' => 'Karen K. Keys-Gamarra',
        'correct' => 'kkkeys-gamarra');
$names[] = array(
        'casual' => 'Graves, Angelina',
        'full' => 'Angelia Williams Graves',
        'correct' => 'awgraves');
$names[] = array(
        'casual' => 'VanValkenburg, Schuyler',
        'full' => 'Schuyler T. VanValkenburg',
        'correct' => 'stvanvalkenburg');
$names[] = array(
        'casual' => 'Bennett-Parker, Elizabeth',
        'full' => 'Elizabeth B. Bennett-Parker',
        'correct' => 'ebbennett-parker');

$failures = 0;
foreach ($names as $name) {
    $shortname = $import -> create_shortname($name['casual'], $name['full']);
    if ($shortname != $name['correct']) {
        echo 'FAILED: Generated ' . $shortname .' instead of ' . $name['correct'] . "\n";
        $failures++;
    }
}

echo 'Tested generating ' . count($names) . ' shortnames: ' . count($names) - $failures
    . ' passed, ' . $failures . ' failed.' . "\n";

if ($failures > 0)
{
    exit(1);
}

exit(0);
