# LLM Instructions

This file provides guidance to LLMs for working with code in this repository.

## About This Project

Richmond Sunlight Machine is the backend infrastructure that powers [Richmond Sunlight](https://www.richmondsunlight.com), a civic transparency website tracking Virginia's General Assembly. This repository contains scrapers, parsers, and data processing jobs that run on a dedicated server to collect and process legislative data without interfering with the main website's performance.

The codebase scrapes data from Virginia's Legislative Information System (LIS), processes it, and updates a MariaDB database. It handles bills, legislators, votes, committee assignments, fiscal impact statements, and legislative video.

## Common Development Commands

### Local Development Setup

**Start the local Docker environment:**
```bash
./docker-run.sh
```

This script:
- Clones the `richmondsunlight.com` repository's deploy branch if `deploy/database.sql` doesn't exist
- Builds and starts Docker containers (application + MariaDB)
- Waits for MariaDB to be healthy
- Runs `deploy/docker-setup.sh` inside the container to populate the `includes/` directory, or refreshes it if it's older than 12 hours

**Stop the Docker environment:**
```bash
./docker-stop.sh
```

### Testing

**Run all tests:**
```bash
./docker-tests.sh
```

This runs:
1. PHP syntax linting on all `.php` files (excluding `includes/vendor`)
2. PHPUnit test suite with `--testdox` output
3. Standalone PHP test scripts in `deploy/tests/` that don't extend PHPUnit TestCase

**Run PHPUnit tests directly in container:**
```bash
docker exec rs_machine includes/vendor/bin/phpunit --testdox
```

**Run a single test file:**
```bash
docker exec rs_machine includes/vendor/bin/phpunit deploy/tests/BillsCsvParserTest.php
```

**Lint PHP files manually:**
```bash
find . -path './includes/vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
```

### Code Quality

**Run Rector for PHP modernization:**
```bash
includes/vendor/bin/rector process --dry-run
```

Rector is configured to upgrade code to PHP 8.3 standards. It processes the `cron/` and `deploy/` directories.

**Run PHP_CodeSniffer (if installed):**
```bash
includes/vendor/bin/phpcs --standard=PSR12 cron/
```

### Dependency Management

**Install dependencies:**
```bash
composer install
```

Note: Composer is configured to install dependencies to `includes/vendor/` rather than the default `vendor/` directory.

**Update dependencies:**
```bash
composer update
```

### Database Access

**Access MariaDB in container:**
```bash
docker exec -it rs_machine_db mariadb -u ricsun -ppassword richmondsunlight
```

### Running Individual Cron Jobs

All cron jobs can be run manually for testing. The entry point is [cron/run.php](cron/run.php),
which takes task names defined in [cron/tasks.php](cron/tasks.php):

```bash
# See every task, its schedule, and its timeout
docker exec rs_machine php cron/run.php --list

# Run a specific task
docker exec rs_machine php cron/run.php bills
docker exec rs_machine php cron/run.php history
docker exec rs_machine php cron/run.php summaries
docker exec rs_machine php cron/run.php full_text
docker exec rs_machine php cron/run.php fiscal_impact
docker exec rs_machine php cron/run.php legislators
docker exec rs_machine php cron/run.php vote
docker exec rs_machine php cron/run.php dockets
docker exec rs_machine php cron/run.php meetings
docker exec rs_machine php cron/run.php minutes

# Run several, in the order given
docker exec rs_machine php cron/run.php bills summaries

# Run the combined "all" pass
docker exec rs_machine php cron/run.php all

# Run whatever the manifest says is due this minute (what production's heartbeat does)
docker exec rs_machine php cron/run.php --due
```

An unknown task name exits 1 with the list of valid names, so a typo fails immediately rather than
silently doing nothing.

**Note:** `cron/update.php` is the previous entry point. It still works and is unchanged, but
nothing schedules it — `deploy/crontab.txt` invokes `run.php --due` instead. Its
`php cron/update.php <type>` invocations map directly to `php cron/run.php <type>`.

## Architecture Overview

### Core Components

**1. Cron Job System**
The heart of this codebase is a collection of **29 PHP scripts** in `cron/` that run at scheduled intervals (see [deploy/crontab.txt](deploy/crontab.txt)). These jobs:
- Fetch CSV files from Virginia's LIS via FTP (using [fetch_csv.sh](cron/fetch_csv.sh) scripts)
- Parse the CSVs and update the database
- Download PDFs and videos (House and Senate floor sessions)
- Generate exports for public consumption
- Sync data to S3 for public downloads

The 29 cron scripts are:
- Task runner: [run.php](cron/run.php) + [tasks.php](cron/tasks.php) (superseding [update.php](cron/update.php))
- Bill processing: [bills.php](cron/bills.php), [history.php](cron/history.php), [summaries.php](cron/summaries.php), [full_text.php](cron/full_text.php)
- Legislative activity: [vote.php](cron/vote.php), [meetings.php](cron/meetings.php), [dockets.php](cron/dockets.php), [minutes.php](cron/minutes.php)
- Legislator data: [legislators.php](cron/legislators.php), [committee_members.php](cron/committee_members.php), [representatives_table.php](cron/representatives_table.php)
- Bill metadata: [copatrons.php](cron/copatrons.php), [code_sections.php](cron/code_sections.php), [tags.php](cron/tags.php)
- Fiscal data: [fiscal_impact.php](cron/fiscal_impact.php), [summarize_fis.php](cron/summarize_fis.php)
- Analysis: [partisanship.php](cron/partisanship.php), [vote_partisanship.php](cron/vote_partisanship.php)
- Data maintenance: [cleanup.php](cron/cleanup.php), [cache.php](cron/cache.php), [export.php](cron/export.php), [checks.php](cron/checks.php)
- Media downloads: [download_pdfs.php](cron/download_pdfs.php), [poll_house_video.php](cron/poll_house_video.php), [poll_senate_video.php](cron/poll_senate_video.php)
- Utilities: [update_places.php](cron/update_places.php), [update_contributions.php](cron/update_contributions.php), [search_index.php](cron/search_index.php), [ps_send_emails.php](cron/ps_send_emails.php)

**2. Task Runner ([cron/run.php](cron/run.php) + [cron/tasks.php](cron/tasks.php))**

The current entry point. `cron/tasks.php` is a manifest declaring every task — its schedule, its
timeout, whether it belongs to the combined "all" pass, and which CSVs to fetch beforehand.
`cron/run.php` reads that manifest and executes what was asked for.

The runner owns the concerns that were previously absent or scattered:
- **Per-task locking**, keyed on the task name, so a task cannot overlap itself however it was invoked
- **Per-task timeouts** from the manifest, rather than one global 20-minute ceiling
- **Failure isolation** — one task throwing no longer aborts the rest of a pass
- **Per-task timing and structured logging**, including a one-line summary of a whole pass
- **CSV prefetch** declared on the task that consumes the file
- `error_reporting(E_ALL)`, reversing update.php's `E_ERROR`, which hid the warnings worth seeing

Tasks report their outcome as a `TaskResult` — `completed`, `skipped` (correctly had nothing to do)
or `failed` — so "the source file was unchanged" is distinguishable from "the source file could not
be read." Both previously looked identical in the logs.

Available tasks are whatever `cron/tasks.php` declares; run `php cron/run.php --list` for the
current set rather than relying on a list here. As of writing there are 25, of which 12 are in the
"all" pass.

**Task file formats.** The runner accepts two, so conversion can happen file by file:
- **Converted:** the file returns a closure taking a `TaskContext` and returning a `TaskResult`.
  These live in `cron/tasks/` and take precedence over a legacy file of the same name. See
  [cron/tasks/photosynthesis.php](cron/tasks/photosynthesis.php) for the reference example.
- **Legacy:** a bare procedural script in `cron/`, `require`d exactly as update.php did, with
  `$log`, `$db`, `$dbh`, `$mc` and `$session_id` extracted into scope first. Most tasks are still
  this form and need no changes to work.

**3. Previous Orchestrator ([cron/update.php](cron/update.php))**

Superseded by the runner, but still present and still functional. Nothing schedules it.

It dispatched via a chain of `if ($type == 'all' || $type == 'foo')` statements, each doing
`require 'foo.php'`. Two problems motivated the replacement:
- The schedule lived in two places — this file and `deploy/crontab.txt` — with nothing enforcing
  agreement. Seven types drifted into being both inside the "all" pass and separately scheduled,
  so they could run twice concurrently.
- A fatal in one task killed every task after it in an "all" pass, silently: the closing
  `$log->put('Updated ' . $type)` never fired, so the absence of a success line was the only signal.

It also accepted `?type=` over HTTP, meaning anything that could reach the web server could trigger
an update. `run.php` is CLI-only.

**Note:** LIS rate limiting (max 15 requests per 30 seconds) applies to scraping, not API calls.

**Recent Improvements:**
- [cron/history.php](cron/history.php) was recently refactored to use efficient status change detection
- Instead of polling every bill's history, it now caches bill statuses in `cron/bill-statuses.json`
- Only bills with changed statuses trigger LIS API calls for full history
- This dramatically reduces API load and improves performance (see commit [79c3137](https://github.com/openva/rs-machine/commit/79c3137))

**4. CSV Fetching and Parsing**
- CSV files are downloaded from LIS via [cron/fetch_csv.sh](cron/fetch_csv.sh) shell scripts
- Files are stored in `cron/` directory (e.g., `cron/bills.csv`, `cron/committees.csv`)
- Each parser maintains MD5 hashes in `cron/hashes/` to avoid reprocessing unchanged records
  - Hash files are session-specific (e.g., `cron/hashes/bills-30.md5` for session 30)
  - Hashes are serialized PHP arrays with bill number as key, MD5 as value
- [cron/bills.php](cron/bills.php) uses the LIS API to fetch legislation IDs and map them to bill numbers
  - Calls `GetLegislationIdsListAsync` endpoint with session API ID
  - Builds `$bill_ids_map` array mapping lowercase bill numbers to LIS IDs
  - Uses this map to populate the `lis_id` column when inserting/updating bills
- **LIS's `BILLS.CSV` is ASCII-only.** LIS replaces every non-ASCII character with a literal `?`
  when generating it ("Damaré" → "Damar?", "Glory’s" → "Glory?s", "REALTORS®" → "REALTORS?"), so
  the original is unrecoverable from the file and re-encoding cannot help. When a catch line
  contains `?`, [cron/bills.php](cron/bills.php) retrieves the intact title from the LIS API via
  `RsMachine\CatchLineRepair`, accepting it only if it equals the CSV's title with its non-ASCII
  characters masked as `?`.

**5. Data Flow**
```
crontab heartbeat (* * * * * run.php --due)
    ↓ (consults cron/tasks.php for what is due)
cron/run.php
    ↓ (takes the task's lock, then fetches its declared CSVs via fetch_csv.sh)
LIS FTP Server → CSV files in cron/
    ↓
Task file (bills.php, history.php, etc.)
    ↓
MySQL Database
    ↓
Export scripts + S3 sync
    ↓
Public downloads (JSON, PDFs, etc.)
```

Note that CSV fetching now happens *inside* the task, after it takes its lock, rather than as a
separate crontab line scheduled a minute earlier. This closes a race where a fetch could overwrite
a CSV that a concurrent run was mid-parse on.

**6. Shared Code (`includes/` directory)**
This directory is **NOT part of this repository**. It's pulled from the `deploy` branch of the [richmondsunlight.com](https://github.com/openva/richmondsunlight.com/tree/deploy/htdocs/includes) repository during builds and setup. It contains:
- `settings.inc.php` - Production configuration constants (API keys, database credentials)
- `functions.inc.php` - Shared utility functions
- `photosynthesis.inc.php` - Additional utilities for smart portfolios
- Classes like `Log`, `Database`, `Import`, and more
- Composer autoloader from both repositories

**Local Development Setup:**
- [docker-run.sh](docker-run.sh) clones richmondsunlight.com if needed
- [deploy/docker-setup.sh](deploy/docker-setup.sh) populates `includes/` from the clone
- Refreshes `includes/` if it's older than 12 hours
- [deploy/settings-docker.inc.php](deploy/settings-docker.inc.php) overrides production settings for Docker
- Create `settings.local.inc.php` for local overrides (not tracked in git)

**Important:** The `includes/vendor/` directory contains Composer dependencies from both this repository and richmondsunlight.com, because `composer.json` sets `"vendor-dir": "includes/vendor"`.

**7. This Repository's Own Classes (`src/` directory)**

⚠️ **Never add rs-machine's own classes to `includes/`. They will be silently deleted on the next deploy.**

`includes/` is a drop zone for code owned by richmondsunlight.com, and it is destroyed and
repopulated on every build. There is no merge step:

- **CI** ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)) runs `mkdir includes/` then
  `cp richmondsunlight.com/htdocs/includes/*.php includes/`. Note that the bare `mkdir` *fails if
  the directory already exists*, so committing any file under `includes/` also breaks the build.
- **Local Docker** ([deploy/docker-setup.sh](deploy/docker-setup.sh)) runs an explicit
  `rm -Rf includes` before copying. It does preserve locally-modified `class.*.php` files, but via
  a modification-time heuristic (files newer than the modal mtime) intended for hand-editing during
  development. Do not rely on it to persist anything.

Additionally, `.gitignore` ignores `includes/` wholesale, so files there are not tracked at all.

Code that *this* repository owns goes in `src/`, which is tracked in git and deployed untouched:

| | `includes/` | `src/` |
|---|---|---|
| Owned by | richmondsunlight.com | this repository |
| Tracked in git | No | Yes |
| Survives deploy | Replaced wholesale | Yes |
| Namespace | Global | `RsMachine\` (PSR-4) |

`composer.json` maps `"RsMachine\\": "src/"`. Classes there are namespaced, so they need `use`
statements; the legacy `includes/` classes (`Log`, `Database`, `Import`, …) are global and do not:

```php
use RsMachine\TaskContext;   // src/TaskContext.php
use RsMachine\TaskResult;    // src/TaskResult.php
// Log and Database need no import — they are in the global namespace.
```

Current contents of `src/`: `TaskRunner.php`, `TaskContext.php`, `TaskResult.php` — the update task
harness — and `CatchLineRepair.php`, which restores characters that LIS's CSV export destroys (see
"CSV Fetching and Parsing") — and `ImpactStatements.php`, the rules for which fiscal impact statement
is current (see "Fiscal Impact Statements"). When adding a class, ask whether the *website* needs it. If yes, it belongs in the
richmondsunlight.com repo; if no (which is usually the case for cron-related code), it goes in
`src/`.

**8. Test Architecture**
Tests live in `deploy/tests/` and use two patterns:
- PHPUnit test classes (extending `PHPUnit\Framework\TestCase`)
- Standalone PHP scripts that exit with non-zero codes on failure

The test suite is configured via `phpunit.xml` with:
- Bootstrap: `includes/vendor/autoload.php`
- Test directory: `deploy/tests/`
- Test environment variables for `APP_ENV` and `DB_CONNECTION`

**9. Fiscal Impact Statements**
[cron/fiscal_impact.php](cron/fiscal_impact.php) stores `FiscalImpactStatements.csv` in
`fiscal_impact_statements`, one row per bill and reference ID. A reference ID names the bill
version and the agency (`SB738S1F122`: agency 122 on substitute S1; before 2025, `FS1122`). LIS
numbers PDFs in publication order, so the highest-numbered PDF is the newest; the CSV is *not* in
publication order, and lists a reissued statement once per PDF. So the cron keeps the newest PDF
per reference ID, never replaces a stored PDF with a lower-numbered one, and clears `summary` when
the PDF changes. The website shows only the newest statement from each agency, and
[cron/summarize_fis.php](cron/summarize_fis.php) summarizes only those. The rules are in
`RsMachine\ImpactStatements`, which must agree with richmondsunlight.com's
`Bill2::latest_impact_statements()`.

### Key Design Patterns

**Hash-Based Change Detection:**
Most parsers (e.g., [cron/bills.php](cron/bills.php)) maintain serialized hash files in `cron/hashes/` containing MD5 hashes of each record. The pattern is:
1. Load existing hashes from `cron/hashes/{type}-{SESSION_ID}.md5`
2. For each CSV row, calculate `md5(serialize($bill))`
3. Compare with stored hash - if identical, skip processing with `continue`
4. If different or new, update the hash and process the record
5. Save updated hashes back to file with `serialize($hashes)`

This prevents unnecessary database writes and cache invalidations, dramatically improving performance. Example from bills.php:
```php
if (isset($hashes[$number]) && ($hash == $hashes[$number])) {
    continue;  // Skip unchanged bill
}
```

**Memcached Integration:**
The code connects to Memcached and deletes cached entries when updating records (e.g., `$mc->delete('bill-' . $existing_bill['id'])`). This ensures the website serves fresh data.

**Subquery-Based Lookups:**
SQL statements use subqueries extensively to look up foreign keys on the fly, e.g.:
```sql
chief_patron_id = (SELECT person_id FROM terms WHERE lis_id = "..." AND ...)
```

**Modular Cron Jobs:**
Each data source has its own PHP file in `cron/`, making it easy to run, test, and debug individual components independently.

### Database Schema Context

The database structure includes (key tables):
- **`bills`** - Legislation records with:
  - `number` (lowercase, e.g., "hb1234"), `chamber`, `status`, `catch_line`
  - `chief_patron_id` (FK to terms.person_id), `last_committee_id` (FK to committees.id)
  - `session_id` (FK to sessions), `lis_id` (LIS legislation ID for API calls)
  - `date_created`, `date_introduced`
- **`bills_status`** - Status history with date, status code, committee references
- **`bills_full_text`** - Multiple versions of bill text with `bill_id`, `number`, `date_introduced`
- **`terms`** - Legislator terms with `person_id`, `lis_id`, `chamber`, `date_started`, `date_ended`
- **`people`** - Legislator personal information (name, photo, etc.)
- **`committees`** - Committee structure with `lis_id`, `parent_id`, `chamber`, `name`
- **`representatives`** - Legacy table synced with people + terms via `representatives_table.php`
- **`votes`** - Individual legislator votes on bills
- **`tags`** - Bill categorization and tagging

**Schema Patterns:**
- Bill numbers stored in **lowercase** throughout the database
- LIS IDs used extensively for matching between RS and LIS systems
- Session-based partitioning (most queries filter by `session_id`)
- Subquery-based foreign key lookups in INSERT/UPDATE statements

### Database Schema Modifications

**CRITICAL: Never use text-based edit tools on SQL dump files containing binary data.**

The `deploy/database.sql` file contains INSERT statements with binary data (e.g., BLOB, GEOMETRY, POINT types) in tables like `bills_places`. The `coordinates` column contains binary-encoded geographic data that appears as escape sequences like `'\0\0\0\0\0\0\0\0\0\0@3�S�\0\0\0���B@'`.

**Problem:** When text-based editing tools (Edit, Write, sed, awk) read and write SQL dump files, they can corrupt binary data by:
- Re-encoding bytes with different character encodings
- Transforming escape sequences
- Altering byte values during text processing

**Solution:** When modifying database schema:

1. **Preferred approach:** Use ALTER TABLE statements directly on the database instead of editing dump files:
   ```bash
   docker exec -it rs_machine_db mariadb -u ricsun -ppassword richmondsunlight -e \
     "ALTER TABLE bills_status ADD COLUMN chamber ENUM('house','senate') DEFAULT NULL AFTER status"
   ```

2. **If you must edit the dump file:** Use binary-safe tools:
   - Python with binary file mode (`open('file', 'rb')` and `open('file', 'wb')`)
   - Only replace the specific CREATE TABLE definition, avoiding INSERT statements with binary data
   - Never use tools that perform text encoding/decoding transformations

3. **After editing:** Verify no unintended changes occurred:
   ```bash
   git diff deploy/database.sql | grep -v "CREATE TABLE.*bills_status"
   ```
   Check that only your intended table definition changed, not INSERT statements in other tables.

**Example of proper binary-safe editing:**
```python
with open('deploy/database.sql', 'rb') as f:
    content = f.read()

# Replace only the specific table definition
old_def = b'CREATE TABLE `bills_status` (...)'
new_def = b'CREATE TABLE `bills_status` (...with new column...)'
content = content.replace(old_def, new_def, 1)

with open('deploy/database.sql', 'wb') as f:
    f.write(content)
```

### Configuration

**Session Management:**
Configuration constants in `deploy/settings-docker.inc.php` define the current legislative session:
- `SESSION_ID` - Richmond Sunlight's internal session ID (e.g., 30 for 2025)
- `SESSION_LIS_ID` - LIS's session identifier (e.g., '251' for 2025)
- `SESSION_YEAR` - Calendar year (e.g., 2025)
- `SESSION_START` and `SESSION_END` - Date range (e.g., '2025-01-08' to '2025-12-31')
- `SESSION_SUFFIX` - Empty for regular sessions, may be used for special sessions

**Important:** `SESSION_LIS_API_ID` is not defined in settings-docker.inc.php. It must come from the richmondsunlight.com includes/ directory or be defined in a local settings override.

It reaches task files as `$session_api_id`, and [cron/bills.php](cron/bills.php#L18) uses it to call the LIS `GetLegislationIdsListAsync` endpoint. **When it is empty, bills.php skips that lookup silently** — no error, but `bills.lis_id` goes unpopulated. [cron/run.php](cron/run.php) passes it through as `TaskContext::$lisApiSessionId`, guarded with `defined()` so a missing constant does not fatal.

These must be updated annually before each new legislative session.

**Environment-Specific Settings:**
- Production uses `includes/settings.inc.php` from the richmondsunlight.com repository
- Docker uses `deploy/settings-docker.inc.php`
- Local overrides can be placed in `settings.local.inc.php`

**API Keys:**
The code interacts with:
- LIS API (requires `LIS_KEY`)
- LIS FTP (requires `LIS_FTP_USERNAME` and `LIS_FTP_PASSWORD`)
- OpenAI API (for summarization)
- AWS S3 (for hosting downloads)
- Slack (for notifications)

### Deployment

**Production Infrastructure:**
- Runs on a dedicated AWS EC2 Nano instance (separate from main website)
- Deployed via GitHub Actions → AWS CodeDeploy → RS-Machine-Fleet
- CodeDeploy configuration in [appspec.yml](appspec.yml)
- Pre/post-deployment hooks in [deploy/predeploy.sh](deploy/predeploy.sh) and [deploy/postdeploy.sh](deploy/postdeploy.sh)
- This isolation prevents resource-intensive cron jobs from interfering with website performance

**CI/CD Pipeline ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)):**
1. **Build job:**
   - Sets up PHP 8.3 on Ubuntu 22.04
   - Clones `richmondsunlight.com` deploy branch to get shared `includes/` files
   - Installs Composer dependencies for both repositories (with caching)
   - Lints every PHP file the repo owns — `cron/`, `cron/tasks/`, `src/`, `deploy/`, `utilities/` — in parallel with `xargs -P8`, excluding `includes/` (owned by richmondsunlight.com)
   - Runs subset of tests: `bills.php` and `committee_members.php`, against `settings-default.inc.php` placeholders (they parse CSV fixtures and need no real credentials)
   - Uploads a **credential-free** artifact, retained 1 day
2. **Deploy job (conditional on build success, and only from `master`):**
   - Downloads artifact from build job
   - Populates credentials — `config_variables.sh`, `aws_credentials.sh`, `sftp_credentials.sh` run *here*, not in the build
   - Configures AWS credentials
   - Uploads to S3: `s3://deploy.richmondsunlight.com/rs-machine-master.zip`
   - Triggers CodeDeploy to RS-Machine-Fleet with OneAtATime deployment

**Automatic Deployment Triggers:**
- On push to `master` branch
- Manual trigger via workflow_dispatch
- Daily at 4 AM UTC (`cron: 0 4 * * *`) to ensure fresh `includes/` files
- On pull requests (build only, no deploy)

## Important Context

### LIS Rate Limiting
Virginia's LIS server blacklists IPs that exceed **15 requests within a 30-second window**. This applies to:
- ✅ **Web scraping** (parsing HTML pages from lis.virginia.gov)
- ❌ **NOT API calls** (the LIS API at `https://lis.virginia.gov/Legislation/api/` has different limits)

When blacklisted, the server blocks connections for several minutes. Be cautious when adding new scrapers or increasing request frequency.

### Cron Job Timing
Jobs are scheduled to align with when LIS publishes data. **The authoritative source is
[cron/tasks.php](cron/tasks.php)** — run `php cron/run.php --list` for the current table rather than
trusting a copy in prose. As of writing:

- **The "all" pass:** hourly at :01, hours 6-23 (the manifest's `@all` key)
- **Summaries:** :02, hours 6-23
- **History:** every 10 minutes at :03,:13,:23,:33,:43,:53, hours 6-23
- **Meetings:** :03 and :33, hours 6-23
- **Full text:** :11, :31, :51, hours 6-23
- **Votes:** four times daily at 10:04, 13:04, 16:04, 21:04
- **Dockets:** :09, hours 6-15
- **Export to S3:** hourly at :05 (a plain crontab line; not a runner task)

Each task also declares a `timeout` that must fit inside its own scheduling interval —
`TaskManifestTest` fails the build otherwise. This prevents the situation that existed under
`update.php`, where a single global `set_time_limit(1200)` exceeded `history`'s 10-minute interval,
so one slow run would silently eat the next two ticks.

The historical data parser has built-in safeguards requiring 18+ hours between refreshes unless explicitly overridden.

### Autoloading
The PSR-4 autoloader maps:
- `RsMachine\` → `src/`
- `RsMachine\Tests\` → `deploy/tests/`

The `RsMachine\` mapping previously pointed at `includes/`, where it did nothing: every class in
that directory is in the global namespace, so none of them were ever autoloaded. It now points at
`src/`, which holds this repository's own namespaced classes — see "This Repository's Own Classes"
above for why they cannot live in `includes/`.

Most of the legacy code in `cron/` still uses `include`/`require` rather than namespaced classes.

### Code Age and Style
Some code dates back to 2005-2008 and predates modern PHP practices. The codebase intentionally uses:
- **Mix of mysqli and PDO**: `$GLOBALS['db']` for mysqli, `$dbh` for PDO (both initialized by the entry point — [cron/run.php](cron/run.php), or [cron/update.php](cron/update.php) for the legacy path)
- **Global variables**: `$GLOBALS['db']`, `$GLOBALS['banned_words']`
- **Procedural code alongside classes**: Most cron scripts are procedural, shared code uses classes
- **`error_reporting(E_ERROR)`** in [cron/update.php](cron/update.php#L22) - suppresses warnings. Note that [cron/run.php](cron/run.php) deliberately uses `E_ALL` instead; expect new warnings to surface when running tasks through the runner, since they were previously hidden rather than absent.
- **No namespaces in legacy code**: cron scripts use `include`/`require`, not autoloading. New code in `src/` is namespaced under `RsMachine\` and autoloaded.
- **Direct database access**: No ORM, raw SQL with string concatenation and escaping

**Modernization Approach:**
- [rector.php](rector.php) configured to upgrade code to PHP 8.3 standards
- Processes `cron/` and `deploy/` directories
- **Be conservative**: Avoid breaking functionality for the sake of modernization
- **Test thoroughly**: Changes to cron jobs affect production data processing

When adding new code, prefer modern PHP patterns (typed properties, constructor promotion, etc.) while respecting the existing style of files you're modifying.

## Development Tips

- **Always populate `includes/` first**: Use `./docker-run.sh` to handle this automatically. The application will fail without these shared files.
- **Testing individual cron jobs**: Ensure you have necessary CSV files in `cron/` and database records populated. Example:
  ```bash
  docker exec rs_machine php cron/run.php bills
  ```
- **Understanding production schedule**: Check [cron/tasks.php](cron/tasks.php), not the crontab — `deploy/crontab.txt` is now just a one-line heartbeat plus the handful of jobs the runner doesn't manage. `php cron/run.php --list` prints the schedule as a table.
- **LIS credentials required**: The codebase assumes access to LIS's FTP server and API. For local development, `settings-docker.inc.php` stubs out most API keys as empty strings, so some functionality won't work without real credentials.
- **Local overrides**: Create `settings.local.inc.php` (gitignored) to override configuration constants without modifying tracked files.
- **Memcached dependency**: Some scripts expect Memcached running on localhost:11211. Docker Compose handles this automatically.
- **Missing legislators are normal**: During session start, some bills may reference legislators not yet in the database. These are logged and should be addressed by running the legislators update.

## Recent Development Activity

Based on recent commits, active development focuses on:
- **Performance optimization**: The bill status checking system was recently improved to only poll changed bills (commit 79c3137, 498fd57)
- **Shared includes refresh**: Regular updates to the `includes/` directory refresh mechanism (commit f710052)
- **Documentation improvements**: Adding LLM-specific guidance for code maintenance (commit 2eaefa5)

This is a mature, production-critical system that prioritizes stability and data integrity over rapid feature development. Changes should be tested thoroughly and deployed incrementally.
