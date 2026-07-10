# Changelog

All notable changes to `pingark/pingark-laravel` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `pingark:sync` now guides new users: running it without `PINGARK_API_KEY`
  points at creating a free PingArk account, and a successful sync prints the
  dashboard URL where the new checks live.

## [1.0.0] - 2026-07-03

Initial public release.

### Added

- The `->pingArk()` scheduler macro: chain it onto any scheduled task to ping
  start before the run, success after, and a failure ping (with the captured
  output, and the real exit code for commands) when it fails.
- The `PingArk` facade with one method per ping signal: `start`, `success`,
  `fail` (accepts an exception for context), `exitCode`, and `log`.
- A `default_check` config option, so the facade signals can be called with no
  argument in a single-job application.
- `PingArk::api()`, a management API client for creating, reading, updating,
  pausing, resuming, and deleting checks, and for listing pings, flips, and
  channels.
- The `pingark:sync` command, which registers your whole schedule as checks
  through the management API, with `--dry-run` and `--prune` (report-only).
- A configurable user agent so plugin pings are easy to spot in ping history.
