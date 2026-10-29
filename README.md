# Richmond Sunlight Machine

The scrapers, parsers, etc. that drive the backend of [Richmond Sunlight](/openva/richmondsunlight.com/).

<a href="https://codeclimate.com/github/openva/rs-machine/maintainability"><img src="https://api.codeclimate.com/v1/badges/243cb50e4c1f976987d5/maintainability" /></a> [![Deploy Process](https://github.com/openva/rs-machine/actions/workflows/deploy.yml/badge.svg)](https://github.com/openva/rs-machine/actions/workflows/deploy.yml)

## Purpose
A couple of dozen cron jobs drive Richmond Sunlight. They fetch updates to legislation, perform routine maintenence on data, export bulk downloads, etc. This tends to create problematic spikes on server resources, which can interfere with serving up web pages. So they're run on a separate instance.

## Run Locally
Machine can be stood up locally with `./docker-run.sh`, and then tests can be run with `./docker-tests.sh`.

## Running Update Tasks
Update tasks are defined in [cron/tasks.php](cron/tasks.php) and run via [cron/run.php](cron/run.php):

```bash
php cron/run.php --list        # every task, its schedule, and its timeout
php cron/run.php summaries     # run one task
php cron/run.php bills vote    # run several, in the order given
php cron/run.php all           # run the combined "all" pass
php cron/run.php --due         # run whatever is scheduled for this minute
```

In production, `deploy/crontab.txt` contains a single per-minute `--due` heartbeat rather than one
line per task; the schedules themselves live in `cron/tasks.php`. **To change when something runs,
edit that file, not the crontab.** It is covered by `deploy/tests/TaskManifestTest.php`, which fails
the build if a task is both individually scheduled and part of the "all" pass, if a task's timeout
exceeds its own interval, or if a cron expression doesn't parse.

Each task takes a lock keyed on its name, so a task cannot overlap itself however it was invoked,
and one failing task no longer aborts the rest of a pass.

This replaces `cron/update.php`, which is still present but no longer scheduled. Its `php
cron/update.php <type>` invocations map to `php cron/run.php <type>`.

## JSONL Bill Exports
Nightly JSONL exports are written to `downloads/bills-YYYY.jsonl` using the public Richmond Sunlight API.

To refresh the current year:
```bash
php cron/run.php export
```

To refresh all years since 2006, set `$export_all_years_jsonl = true;` in `cron/export.php` and run:
```bash
php cron/run.php export
```

Quick validation (line count should match the bill list size):
```bash
wc -l downloads/bills-YYYY.jsonl
```

### Refreshing the Database
The test database, `deploy/database.sql`, is built from [richmondsunlight.com](https://github.com/openva/richmondsunlight.com)'s
deploy branch by `deploy/build_database.sh`, which `./docker-run.sh` runs. MariaDB loads it only
when its volume is first created, so to reload it with the latest data:

```bash
docker compose down -v
./docker-run.sh
```

## History
Some of this code was written in 2005. Most of it was written in 2007–08. It was shoveled out of `/cron/` and onto here in late 2017, both to make it possible to run it on a separate server, but also to isolate it to permit better testing and upgrades.

## Infrastructure
It lives on a dedicated EC2 Nano instance. Source updates are delivered via GitHub Actions -> AWS CodeDeploy.

Note that [the `includes/` directory is pulled from the `deploy` branch of `richmondsunlight.com` repository](https://github.com/openva/richmondsunlight.com/tree/deploy/htdocs/includes) on each build.

### `src/` vs. `includes/`

There are two directories of PHP classes, and the distinction matters:

| | `includes/` | `src/` |
|---|---|---|
| Owned by | `richmondsunlight.com` | this repository |
| In git here? | No — `.gitignore`d | Yes |
| On deploy | **Deleted and repopulated** from the website repo | Deployed as-is |
| Namespace | Global (`class.Log.php` → `Log`) | `RsMachine\` (PSR-4) |

**Never put rs-machine's own code in `includes/`. It will be deleted on the next deploy.** Both
population paths wipe the directory first: the CI workflow does `mkdir includes/` followed by a
copy from the website checkout, and [deploy/docker-setup.sh](deploy/docker-setup.sh) runs an
explicit `rm -Rf includes`. (`docker-setup.sh` has a heuristic that preserves locally-modified
`class.*.php` files by comparing modification times, but that is a convenience for local
development, not a guarantee, and CI has no equivalent.)

Anything this repository owns belongs in `src/`, autoloaded via the `RsMachine\` PSR-4 mapping in
`composer.json`. The task runner (`TaskRunner`, `TaskContext`, `TaskResult`) lives there for
exactly this reason.

Because `src/` classes are namespaced and `includes/` classes are not, code that uses both needs
`use` statements for the former and nothing for the latter:

```php
use RsMachine\TaskContext;   // from src/
use RsMachine\TaskResult;    // from src/
                             // Log, Database, etc. are global — no import needed
```
