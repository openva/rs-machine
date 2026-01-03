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
The heart of this codebase is a collection of cron jobs in `cron/` that run at scheduled intervals (see `deploy/crontab.txt`). These jobs:
- Fetch CSV files from Virginia's LIS via FTP (using `fetch_csv.sh` scripts)
- Parse the CSVs and update the database
- Download PDFs and videos
- Generate exports for public consumption
- Sync data to S3

**2. Main Update Orchestrator (`cron/update.php`)**
This is the central dispatcher that:
- Accepts a "type" parameter (either via CLI argument or GET parameter)
- Includes the appropriate specialized PHP script based on the type
- Manages database connections (both mysqli and PDO)
- Implements rate limiting for LIS API calls (max 15 requests per 30 seconds)
- Sets a 20-minute execution time limit

The update types are modular - each type requires a separate PHP file in `cron/` (e.g., `bills.php`, `history.php`, `summaries.php`, etc.).

**3. CSV Fetching and Parsing**
- CSV files are downloaded from LIS via `fetch_csv.sh` shell scripts
- Files are stored in `cron/` directory (e.g., `cron/bills.csv`, `cron/committees.csv`)
- Each parser maintains MD5 hashes in `cron/hashes/` to avoid reprocessing unchanged records
- Parsers use the LIS API to fetch legislation IDs and map them to bill numbers

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
This directory is NOT part of this repository. It's pulled from the `deploy` branch of the `richmondsunlight.com` repository during builds and setup. It contains:
- `settings.inc.php` - Configuration constants
- `functions.inc.php` - Shared utility functions
- `photosynthesis.inc.php` - Additional utilities
- Classes like `Log`, `Database`, `Import`, etc.

During local development, `docker-run.sh` and `deploy/docker-setup.sh` handle populating this directory.

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
Most parsers (e.g., `bills.php`) maintain serialized hash files in `cron/hashes/` containing MD5 hashes of each record. Before processing, they check if the hash has changed. This prevents unnecessary database writes and improves performance.

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

The database structure includes:
- `bills` - Legislation records with number, chamber, status, catch_line, chief_patron_id, etc.
- `terms` - Legislator terms with person_id, lis_id, chamber, date ranges
- `people` - Legislator personal information
- `committees` - Committee structure with parent/child relationships
- `representatives` - Legacy table synced with people + terms

Bill numbers are stored in lowercase and queries use LIS IDs extensively to match data between systems.

### Configuration

**Session Management:**
Configuration constants in `deploy/settings-docker.inc.php` define the current legislative session:
- `SESSION_ID` - Richmond Sunlight's internal session ID
- `SESSION_LIS_ID` - LIS's session identifier
- `SESSION_LIS_API_ID` - LIS API session identifier (different from LIS_ID)
- `SESSION_YEAR` - Calendar year
- `SESSION_START` and `SESSION_END` - Date range

These must be updated annually.

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
- Runs on AWS EC2 Nano instance
- Deployed via GitHub Actions → AWS CodeDeploy
- CodeDeploy configuration in `appspec.yml`
- Pre/post-deployment hooks in `deploy/predeploy.sh` and `deploy/postdeploy.sh`

**CI/CD Pipeline (`.github/workflows/deploy.yml`):**
1. Build job:
   - Sets up PHP 8.3
   - Clones `richmondsunlight.com` deploy branch to get `includes/` files
   - Installs Composer dependencies for both repos
   - Lints all PHP files in `cron/`
   - Runs subset of tests
   - Creates deployment zip with secrets populated
2. Deploy job:
   - Uploads to S3
   - Triggers CodeDeploy to RS-Machine-Fleet

**Daily Redeployment:**
The workflow runs on a schedule (`cron: 0 4 * * *`) to ensure fresh `includes/` files daily at 4 AM.

## Important Context

### LIS Rate Limiting
Virginia's LIS server blacklists IPs that exceed 15 requests within a 30-second window. Respect this limit when scraping (but not for API calls).

### Cron Job Timing
Jobs are carefully scheduled (see `deploy/crontab.txt`) to align with when LIS publishes data:
- Bill data updates at 6:51 AM, so fetching starts at 6:55 AM
- Summaries update at 11:53 AM, fetched at 11:59 AM
- Historical data and votes have specific collection windows

### Autoloading
The PSR-4 autoloader maps:
- `RsMachine\` → `includes/`
- `RsMachine\Tests\` → `deploy/tests/`

However, most of the legacy code in `cron/` uses `include`/`require` rather than namespaced classes.

### Code Age and Style
Some code dates back to 2005-2008 and predates modern PHP practices. The codebase uses:
- Mix of mysqli and PDO
- Global variables (e.g., `$GLOBALS['db']`)
- Procedural code alongside classes
- `error_reporting(E_ERROR)` in some scripts

Rector is configured to help modernize this code to PHP 8.3 standards, but avoid introducing changes that break functionality.

## Development Tips

- The `includes/` directory must be populated before running any code. Use `./docker-run.sh` to handle this automatically.
- When testing individual cron jobs, ensure you have the necessary CSV files in `cron/` and database records populated.
- Check `deploy/crontab.txt` to understand the production schedule and data dependencies.
- The codebase assumes access to LIS's FTP server and API, which requires credentials not included in the repository.
- For local development, `settings-docker.inc.php` stubs out most API keys as empty strings.
