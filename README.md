# Richmond Sunlight Machine

The scrapers, parsers, etc. that drive the backend of [Richmond Sunlight](/openva/richmondsunlight.com/).

<a href="https://codeclimate.com/github/openva/rs-machine/maintainability"><img src="https://api.codeclimate.com/v1/badges/243cb50e4c1f976987d5/maintainability" /></a> [![Deploy Process](https://github.com/openva/rs-machine/actions/workflows/deploy.yml/badge.svg)](https://github.com/openva/rs-machine/actions/workflows/deploy.yml)

## Purpose
A couple of dozen cron jobs drive Richmond Sunlight. They fetch updates to legislation, perform routine maintenence on data, export bulk downloads, etc. This tends to create problematic spikes on server resources, which can interfere with serving up web pages. So they're run on a separate instance.

## Run Locally
Machine can be stood up locally with `./docker-run.sh`, and then tests can be run with `./docker-tests.sh`.

## JSONL Bill Exports
Nightly JSONL exports are written to `downloads/bills-YYYY.jsonl` using the public Richmond Sunlight API.

To refresh the current year:
```bash
php cron/update.php export
```

To refresh all years since 2006, set `$export_all_years_jsonl = true;` in `cron/export.php` and run:
```bash
php cron/update.php export
```

Quick validation (line count should match the bill list size):
```bash
wc -l downloads/bills-YYYY.jsonl
```

### Refreshing the Database
If you've updated `deploy/database.sql` and need to reload it into the MariaDB container:

```bash
docker exec -i rs_machine_db mariadb -u ricsun -ppassword richmondsunlight < deploy/database.sql
```

Alternatively, for a complete refresh (removes all data and reloads from scratch):

```bash
./docker-stop.sh
docker compose down
docker volume rm rs-machine_db_data
./docker-run.sh
```

## History
Some of this code was written in 2005. Most of it was written in 2007–08. It was shoveled out of `/cron/` and onto here in late 2017, both to make it possible to run it on a separate server, but also to isolate it to permit better testing and upgrades.

## Infrastructure
It lives on a dedicated EC2 Nano instance. Source updates are delivered via GitHub Actions -> AWS CodeDeploy.

Note that [the `includes/` directory is pulled from the `deploy` branch of `richmondsunlight.com` repository](https://github.com/openva/richmondsunlight.com/tree/deploy/htdocs/includes) on each build.