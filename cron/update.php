<?php

###
# Update Database
#
# PURPOSE
# Parses CSV files and updates the database with their content.
#
# NOTES
# * The $session_id variable must be manually set when importing bills from old sessions.
# * Requests to LIS' server must not exceed 15 within a 30-second window, or else the server will
#   blacklist any further connections for a period of a few minutes.
# * history.csv will only be parsed if it's at least 18 hours old or if this page is requested with
#   ?history=y
# * The cut-off date of when to believe LIS' claim that a bill has been continued will need to be
#   updated annually.  Right now it's set to February 1, 2008, because all bills currently flagged
#   as "continued" have actually been killed, but bills will be able to continued after the '08
#   session.
#
###

error_reporting(E_ERROR);
ini_set('display_errors', '1');

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once(__DIR__ . '/../includes/settings.inc.php');
include_once(__DIR__ . '/../includes/functions.inc.php');
include_once(__DIR__ . '/../includes/photosynthesis.inc.php');
include_once(__DIR__ . '/../includes/vendor/autoload.php');

$log = new Log();

# Don't bother to run this if the General Assembly isn't in session.
if (LEGISLATIVE_SEASON == false) {
    exit();
}

# Set a time limit of 20 minutes for this script to run.
set_time_limit(1200);

# FUNDAMENTAL VARIABLES
$session_id = SESSION_ID;
$session_year = SESSION_YEAR;
$dlas_session_id = SESSION_LIS_ID;
$max_age_bills = 50 * 60;           // for how many seconds to refuse to refresh the bills CSV
$max_age_history = 12 * 60 * 60;    // for how many seconds to refuse to refresh the history CSV


# WHAT TYPE OF AN UPDATE WE'RE RUNNING.
# If this page is loaded straight-up, we probably want to run everything. But we also provide the
# options of running any of the below individually, in order to update particular components or
# portions of the website.

# If this is being run from the CLI
if (PHP_SAPI === 'cli') {
    # If there are no command-line switches, update everything.
    if ($_SERVER['argc'] <= 1) {
        $type = 'all';
    } else {
        $type = $_SERVER['argv'][1];
        if ($type == 'history') {
            $_GLOBAL['history'] = 'y';
        }
    }
}
# If this is being run via HTTP.
else {
    if (!isset($_GET['type'])) {
        $type = 'all';
    } else {
        $type = $_GET['type'];
        if ($type == 'history') {
            $_GLOBAL['history'] = 'y';
        }
    }
}

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific page.
$database = new Database();
$database->connect_mysqli();
$dbh = new PDO(PDO_DSN, PDO_USERNAME, PDO_PASSWORD);
if ($dbh === false) {
    $log->put('Could not connect to database.', 8);
    die('Could not connect to database.');
}

# Run bills.php, which contains the functionality that updates the bill listing. Note that this will
# only be run only once hourly--that is, it does no good to request that it be done more than once
# an hour, because it won't fetch the file anew from LIS' server.
if (($type == 'all') || ($type == 'bills')) {
    require 'bills.php';
}

# Run history.php, which contains the functionality that updates the status histories of every bill.
# Note that this will only be run when specifically requested at the command line, via ?history=y.
if (($type == 'all') || ($type == 'history')) {
    require 'history.php';
}

# Run summaries.php, which contains the functionality that gathers and stores the full summaries for
# each bill.
if (($type == 'all') || ($type == 'summaries')) {
    require 'summaries.php';
}

# Run full_text.php, which contains the functionality that gathers and stores the full text of each
# bill.
if (($type == 'all') || ($type == 'full_text')) {
    require 'full_text.php';
}

# Run partisanship.php, which contains the functionality that determines how partisan that each
# legislator is.
if (($type == 'all') || ($type == 'partisanship')) {
    require 'partisanship.php';
}

# Run copatrons.php, which gathers all of the copatronage data for every bill.
if (($type == 'all') || ($type == 'copatrons')) {
    require 'copatrons.php';
}

# Run code_sections.php, which gathers all of the mentions of Code of VA sections in bill text.
if (($type == 'all') || ($type == 'code_sections')) {
    require 'code_sections.php';
}

# Run cleanup.php, which has a bunch of functions that, well, clean up the data--convert character
# sets, translate statuses into English, establish summary hashes, etc. All of the niceties that
# make Richmond Sunlight Richmond Sunlight.
if (($type == 'all') || ($type == 'cleanup')) {
    require 'cleanup.php';
}

# Check for missing / excess legislators.
if ($type == 'legislators') {
    require 'legislators.php';
}

# Run cache.php, which has a bunch of functions that preemptively load data into the in-memory
# cache.
if (($type == 'all') || ($type == 'cache')) {
    require 'cache.php';
}

# Run export.php, which gathers up data to be exported into flat files for folks to download and
# play with.
if (($type == 'all') || ($type == 'export')) {
    require 'export.php';
}

# Retrieve PDF copies of bills.
if (($type == 'all') || ($type == 'download_pdfs')) {
    require 'download_pdfs.php';
}

# Update dockets
if (($type == 'all') || ($type == 'dockets')) {
    # Disabled until the script is fixed to reflect the new server
    require 'dockets.php';
}

# Update the meeting schedule
if (($type == 'all') || ($type == 'meetings')) {
    require 'meetings.php';
}

# Update the minutes.
if ($type == 'minutes') {
    require 'minutes.php';
}

# Update the voting record.
if ($type == 'vote') {
    require 'vote.php';
}

# Auto-tag some bills.
if (($type == 'all') || ($type == 'tags')) {
    require 'tags.php';
}

# Run integrity checks.
if (($type == 'all') || ($type == 'checks')) {
    require 'checks.php';
}

# Retrieve parse fiscal impact statements CSV.
if (($type == 'all') || ($type == 'fiscal_impact')) {
    require 'fiscal_impact.php';
}

# Summarize fiscal impact statements.
if (($type == 'all') || ($type == 'summarize_fis')) {
    require 'summarize_fis.php';
}

# UPDATE DASHBOARD SMART PORTFOLIOS
# Step through every smart portfolio and update its constituent bills.
if (($type == 'all') || ($type == 'photosynthesis')) {
    $sql = 'SELECT id
			FROM dashboard_portfolios
			WHERE watch_list_id IS NOT NULL';
    $result = mysqli_query($GLOBALS['db'], $sql);
    while ($portfolio = mysqli_fetch_array($result)) {
        populate_smart_portfolio($portfolio['id']);
    }
}

$log->put('Updated ' . $type, 2);
