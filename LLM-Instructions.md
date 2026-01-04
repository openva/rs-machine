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

All cron jobs can be run manually for testing. The main entry point is `cron/update.php`, which accepts different types:

```bash
# Run specific update type
docker exec rs_machine php cron/update.php bills
docker exec rs_machine php cron/update.php history
docker exec rs_machine php cron/update.php summaries
docker exec rs_machine php cron/update.php full_text
docker exec rs_machine php cron/update.php fiscal_impact
docker exec rs_machine php cron/update.php legislators
docker exec rs_machine php cron/update.php vote
docker exec rs_machine php cron/update.php dockets
docker exec rs_machine php cron/update.php meetings
docker exec rs_machine php cron/update.php minutes

# Run all updates
docker exec rs_machine php cron/update.php all
```

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
- Core update dispatcher: [update.php](cron/update.php)
- Bill processing: [bills.php](cron/bills.php), [history.php](cron/history.php), [summaries.php](cron/summaries.php), [full_text.php](cron/full_text.php)
- Legislative activity: [vote.php](cron/vote.php), [meetings.php](cron/meetings.php), [dockets.php](cron/dockets.php), [minutes.php](cron/minutes.php)
- Legislator data: [legislators.php](cron/legislators.php), [committee_members.php](cron/committee_members.php), [representatives_table.php](cron/representatives_table.php)
- Bill metadata: [copatrons.php](cron/copatrons.php), [code_sections.php](cron/code_sections.php), [tags.php](cron/tags.php)
- Fiscal data: [fiscal_impact.php](cron/fiscal_impact.php), [summarize_fis.php](cron/summarize_fis.php)
- Analysis: [partisanship.php](cron/partisanship.php), [vote_partisanship.php](cron/vote_partisanship.php)
- Data maintenance: [cleanup.php](cron/cleanup.php), [cache.php](cron/cache.php), [export.php](cron/export.php), [checks.php](cron/checks.php)
- Media downloads: [download_pdfs.php](cron/download_pdfs.php), [poll_house_video.php](cron/poll_house_video.php), [poll_senate_video.php](cron/poll_senate_video.php)
- Utilities: [update_places.php](cron/update_places.php), [update_contributions.php](cron/update_contributions.php), [search_index.php](cron/search_index.php), [ps_send_emails.php](cron/ps_send_emails.php)

**2. Main Update Orchestrator ([cron/update.php](cron/update.php))**
This is the central dispatcher that:
- Accepts a "type" parameter (either via CLI argument `php update.php bills` or GET parameter `?type=bills`)
- Includes the appropriate specialized PHP script via `require` based on the type
- Manages database connections (both mysqli via `Database` class and PDO)
- Sets a 20-minute execution time limit (`set_time_limit(1200)`)
- Suppresses warnings with `error_reporting(E_ERROR)` - legacy decision

The update types are modular - each type requires a separate PHP file in `cron/`. Available types:
- `all` (default) - Runs most update types sequentially
- `bills`, `history`, `summaries`, `full_text` - Bill data updates
- `legislators`, `copatrons`, `code_sections` - Legislator and bill metadata
- `vote`, `dockets`, `meetings`, `minutes` - Legislative activity
- `fiscal_impact`, `summarize_fis` - Fiscal impact statements
- `cleanup`, `cache`, `export`, `download_pdfs` - Data maintenance
- `tags`, `checks`, `partisanship`, `photosynthesis` - Analysis and categorization
- `representatives_table` - Legacy table sync

**Note:** LIS rate limiting (max 15 requests per 30 seconds) applies to scraping, not API calls.

**Recent Improvements:**
- [cron/history.php](cron/history.php) was recently refactored to use efficient status change detection
- Instead of polling every bill's history, it now caches bill statuses in `cron/bill-statuses.json`
- Only bills with changed statuses trigger LIS API calls for full history
- This dramatically reduces API load and improves performance (see commit [79c3137](https://github.com/openva/rs-machine/commit/79c3137))

**3. CSV Fetching and Parsing**
- CSV files are downloaded from LIS via [cron/fetch_csv.sh](cron/fetch_csv.sh) shell scripts
- Files are stored in `cron/` directory (e.g., `cron/bills.csv`, `cron/committees.csv`)
- Each parser maintains MD5 hashes in `cron/hashes/` to avoid reprocessing unchanged records
  - Hash files are session-specific (e.g., `cron/hashes/bills-30.md5` for session 30)
  - Hashes are serialized PHP arrays with bill number as key, MD5 as value
- [cron/bills.php](cron/bills.php) uses the LIS API to fetch legislation IDs and map them to bill numbers
  - Calls `GetLegislationIdsListAsync` endpoint with session API ID
  - Builds `$bill_ids_map` array mapping lowercase bill numbers to LIS IDs
  - Uses this map to populate the `lis_id` column when inserting/updating bills

**4. Data Flow**
```
LIS FTP Server
    ↓ (fetch_csv.sh)
CSV files in cron/
    ↓ (cron/update.php)
Specialized parsers (bills.php, history.php, etc.)
    ↓
MySQL Database
    ↓
Export scripts + S3 sync
    ↓
Public downloads (JSON, PDFs, etc.)
```

**5. Shared Code (`includes/` directory)**
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

**6. Test Architecture**
Tests live in `deploy/tests/` and use two patterns:
- PHPUnit test classes (extending `PHPUnit\Framework\TestCase`)
- Standalone PHP scripts that exit with non-zero codes on failure

The test suite is configured via `phpunit.xml` with:
- Bootstrap: `includes/vendor/autoload.php`
- Test directory: `deploy/tests/`
- Test environment variables for `APP_ENV` and `DB_CONNECTION`

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

### Configuration

**Session Management:**
Configuration constants in `deploy/settings-docker.inc.php` define the current legislative session:
- `SESSION_ID` - Richmond Sunlight's internal session ID (e.g., 30 for 2025)
- `SESSION_LIS_ID` - LIS's session identifier (e.g., '251' for 2025)
- `SESSION_YEAR` - Calendar year (e.g., 2025)
- `SESSION_START` and `SESSION_END` - Date range (e.g., '2025-01-08' to '2025-12-31')
- `SESSION_SUFFIX` - Empty for regular sessions, may be used for special sessions

**Important:** `SESSION_LIS_API_ID` is referenced in [cron/update.php:40](cron/update.php#L40) but not defined in settings-docker.inc.php. This constant must come from the richmondsunlight.com includes/ directory or be defined in a local settings override.

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
   - Lints all PHP files in `cron/` directory (parallel execution with `xargs -P8`)
   - Runs subset of tests: `bills.php` and `committee_members.php`
   - Populates secrets via environment variables (LIS credentials, AWS keys, API tokens)
   - Creates deployment zip artifact
2. **Deploy job (conditional on build success):**
   - Downloads artifact from build job
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
Jobs are carefully scheduled (see [deploy/crontab.txt](deploy/crontab.txt)) to align with when LIS publishes data:
- **Bills:** LIS updates at 6:51 AM, RS fetches at 6:55 AM (55 minutes past, hourly from 6-23)
- **Summaries:** LIS updates at 11:53 AM, RS fetches at 11:59 AM (daily)
- **History:** Every 10 minutes during session hours (02,12,22,32,42,52 past the hour, 6-23)
- **Votes:** Four times daily (10:00, 13:00, 16:00, 21:00)
- **Meetings/Dockets:** Hourly during session (6-15)
- **Full text:** Every 10 minutes (11,21,31,41,51 past the hour)
- **Export to S3:** Hourly at :05 (syncs downloads directory)

The historical data parser has built-in safeguards requiring 18+ hours between refreshes unless explicitly overridden.

### Autoloading
The PSR-4 autoloader maps:
- `RsMachine\` → `includes/`
- `RsMachine\Tests\` → `deploy/tests/`

However, most of the legacy code in `cron/` uses `include`/`require` rather than namespaced classes.

### Code Age and Style
Some code dates back to 2005-2008 and predates modern PHP practices. The codebase intentionally uses:
- **Mix of mysqli and PDO**: `$GLOBALS['db']` for mysqli, `$dbh` for PDO (both initialized in update.php)
- **Global variables**: `$GLOBALS['db']`, `$GLOBALS['banned_words']`
- **Procedural code alongside classes**: Most cron scripts are procedural, shared code uses classes
- **`error_reporting(E_ERROR)`** in [cron/update.php](cron/update.php#L22) - suppresses warnings
- **No namespaces in legacy code**: cron scripts use `include`/`require`, not autoloading
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
  docker exec rs_machine php cron/update.php bills
  ```
- **Understanding production schedule**: Check [deploy/crontab.txt](deploy/crontab.txt) to see when jobs run and their data dependencies.
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
