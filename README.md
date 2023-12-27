# Richmond Sunlight Machine

The scrapers, parsers, etc. that drive the backend of [Richmond Sunlight](/openva/richmondsunlight.com/).

<a href="https://codeclimate.com/github/openva/rs-machine/maintainability"><img src="https://api.codeclimate.com/v1/badges/243cb50e4c1f976987d5/maintainability" /></a> [![Deploy Process](https://github.com/openva/rs-machine/actions/workflows/deploy.yml/badge.svg)](https://github.com/openva/rs-machine/actions/workflows/deploy.yml)

## Purpose
A couple of dozen cron jobs drive Richmond Sunlight. They fetch updates to legislation, perform routine maintenence on data, export bulk downloads, etc. This tends to create problematic spikes on server resources, which can interfere with serving up web pages. So they're run on a separate instance.

## History
Some of this code was written in 2005. Most of it was written in 2007–08. It was shoveled out of `/cron/` and onto here in late 2017, both to make it possible to run it on a separate server, but also to isolate it to permit better testing and upgrades.

## Infrastructure
It lives on a dedicated EC2 Nano instance. Source updates are delivered via Travis CI -> CodeDeploy. (Note that [the `includes/` directory is pulled from the `deploy` branch of `richmondsunlight.com` repository](https://github.com/openva/richmondsunlight.com/tree/deploy/htdocs/includes) on each build.)
