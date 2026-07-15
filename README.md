# PingArk for Laravel

[![Latest version on Packagist](https://img.shields.io/packagist/v/pingark/pingark-laravel)](https://packagist.org/packages/pingark/pingark-laravel)
[![Tests](https://github.com/pingark/pingark-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/pingark/pingark-laravel/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/pingark/pingark-laravel)](LICENSE)

Monitor Laravel scheduled tasks and cron jobs, and get alerted the moment one
silently stops running. The official [PingArk](https://pingark.com) package does the
plumbing for you: each task pings when it starts and when it finishes, and a failure
sends the exit code and the actual exception along with it, so you know why a job
broke, not just that a ping is late.

PingArk is a monitoring service for cron jobs and scheduled tasks. A job sends an
outbound ping when it runs. If the ping does not arrive on schedule, plus a grace
period you choose, PingArk alerts you. This package is the quickest way to wire that
up in a Laravel application, though PingArk works with any job in any language over a
plain HTTP request. A [free account](https://pingark.com/register) covers 20 checks
with no card required.

- [Full guide](https://pingark.com/docs/laravel-plugin)
- [Ping API reference](https://pingark.com/docs/ping-api)
- [Management API reference](https://pingark.com/docs/api)

## Contents

- [What you get](#what-you-get)
- [Requirements](#requirements)
- [Install](#installation)
- [Configure](#configuration)
- [Quick start: watch a scheduled task](#quick-start-watch-a-scheduled-task)
- [Register your whole schedule](#register-your-whole-schedule)
- [Send signals by hand](#send-signals-by-hand)
  - [Capture an exception](#capture-an-exception-on-failure)
  - [Report an exit code](#report-an-exit-code)
  - [Log a progress event](#log-a-progress-event)
- [Create and manage checks from code](#create-and-manage-checks-from-code)
- [How it stays out of your way](#how-it-stays-out-of-your-way)

## What you get

- A one-line `->pingArk()` you chain onto any scheduled task.
- Automatic start, success, and failure pings around every run, so PingArk can
  measure how long a task takes and show you the error when one fails.
- A `PingArk` facade to send signals by hand from inside a job, including progress
  notes and captured exceptions.
- A `pingark:sync` command that registers your whole schedule as checks in one go.
- A small management API client for creating and configuring checks from code.
- A promise that monitoring never breaks the job it is watching. Every ping has a
  short timeout and swallows its own errors.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13

## Installation

```bash
composer require pingark/pingark-laravel
```

Publish the config file if you want to change any defaults (optional):

```bash
php artisan vendor:publish --tag=pingark-config
```

## Configuration

Add your project ping key to `.env`. You will find it in PingArk under your project's
settings.

```dotenv
PINGARK_PING_KEY=your-project-ping-key

# Optional. Only needed for pingark:sync and the management API client.
PINGARK_API_KEY=your-read-write-api-key
```

That is all most applications need. The full set of options lives in `config/pingark.php`:

| Option | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `PINGARK_ENABLED` | `true` | Master switch. Set to `false` to silence every ping (handy on staging). |
| `base_url` | `PINGARK_BASE_URL` | `https://ping.pingark.com` | The ingestion base URL your pings are sent to. |
| `api_url` | `PINGARK_API_URL` | `https://api.pingark.com` | The management API base URL, used by `pingark:sync` and `PingArk::api()`. |
| `ping_key` | `PINGARK_PING_KEY` | `null` | The project ping key that your task pings hit. |
| `api_key` | `PINGARK_API_KEY` | `null` | A read-write API key, used by `pingark:sync` and `PingArk::api()`. |
| `default_grace` | `PINGARK_DEFAULT_GRACE` | `600` | Grace period, in seconds, for checks created by `pingark:sync`. |
| `timeout` | `PINGARK_TIMEOUT` | `5` | Outbound ping timeout in seconds. Short, so a slow network never hangs a job. |
| `user_agent` | `PINGARK_USER_AGENT` | `PingArk-Laravel` | The user agent sent with every ping, so you can spot the plugin in ping history. |
| `default_check` | `PINGARK_DEFAULT_CHECK` | `null` | An optional fallback check slug, so the facade signals can be called with no argument in a single-job app. |

## Quick start: watch a scheduled task

Chain `->pingArk()` onto any task in your schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00')->pingArk();
```

That is the whole integration. On each run the plugin sends a start ping before the
task, a success ping after it finishes, and a failure ping (with the captured output,
and the command's real exit code) if it fails. PingArk measures the run duration for
you from the start and finish pings.

The check slug is derived from the task name, so `backup:run` pings a check named
`backuprun`. When you would rather choose the slug yourself, pass it to `pingArk()`.
The rest of this guide follows one example job, a nightly order import on the check
`nightly-import`:

```php
Schedule::command('import:orders')->dailyAt('02:00')->pingArk('nightly-import');
```

Whichever slug you use must match the check in PingArk. Create it in the dashboard, or
let `pingark:sync` create it for you.

## Register your whole schedule

Rather than creating each check by hand, let the plugin mirror your entire schedule
into PingArk:

```bash
php artisan pingark:sync
```

It creates one check per scheduled task, using the task's cron expression and
timezone, and skips any check that already exists, so it is safe to run again after
you add new tasks. This needs `PINGARK_API_KEY` set to a read-write key.

Preview the changes first with `--dry-run`:

```bash
php artisan pingark:sync --dry-run
```

Find checks that no longer map to a scheduled task with `--prune`. This only reports
them. It never deletes anything, so a check you still want is never removed behind
your back:

```bash
php artisan pingark:sync --prune
```

## Send signals by hand

Sometimes you want to signal PingArk from inside a job rather than around a scheduled
command. The `PingArk` facade gives you one method per signal.

```php
use PingArk\Laravel\Facades\PingArk;

PingArk::start('nightly-import');   // records a start time so duration can be measured
PingArk::success('nightly-import'); // job finished, re-arms the check
PingArk::fail('nightly-import');    // job failed, sends the check down
```

### Capture an exception on failure

Pass the caught exception to `fail()` and its class, message, and stack trace are
attached to the failure, so you can see what went wrong right on the PingArk timeline.

```php
use PingArk\Laravel\Facades\PingArk;

try {
    $this->runImport();
    PingArk::success('nightly-import');
} catch (\Throwable $e) {
    PingArk::fail('nightly-import', $e);
    throw $e;
}
```

### Report an exit code

If you already have a process exit status, send it directly. Zero counts as success
and any non-zero value counts as a failure, and the raw code is recorded either way.

```php
PingArk::exitCode('nightly-import', 137); // 137 is an out-of-memory kill
```

### Log a progress event

A log event records a note on the timeline without changing the check's state. It
never arms, recovers, or alerts. Use it for progress inside a long job.

```php
PingArk::log('nightly-import', 'processed 5,000 of 20,000 rows');
```

## Create and manage checks from code

For setup scripts and tooling, `PingArk::api()` gives you a small client for the
management API. It needs a read-write `PINGARK_API_KEY`. Unlike the ping signals, the
client throws on an error, because a failed setup call is something you want to know
about.

```php
use PingArk\Laravel\Facades\PingArk;

$check = PingArk::api()->createCheck([
    'name' => 'Nightly import',
    'slug' => 'nightly-import',
    'schedule_type' => 'simple',
    'period' => 86400,   // expected every 24 hours
    'grace' => 3600,     // allow an hour late before alerting
    'timezone' => 'UTC',
]);

PingArk::api()->pause($check['id']);
PingArk::api()->resume($check['id']);

$pings = PingArk::api()->pings($check['id']);
$flips = PingArk::api()->flips($check['id']);
$channels = PingArk::api()->channels();
```

## How it stays out of your way

Monitoring should never be the reason a job fails. Every ping this package sends has a
short timeout and swallows its own errors, and it never retries. If PingArk is
unreachable, your job runs exactly as it would without the package, and the next
scheduled run pings again. When PingArk is disabled or the ping key is missing, the
signals are a silent no-op.

The `pingark:sync` command and the `PingArk::api()` client are the exception. They are
setup tools, not part of a running job, so they surface errors rather than hiding
them. When `pingark:sync` runs without an API key it also points you at creating a
free account, and a successful sync prints the dashboard URL where your new checks
live.

## Testing

```bash
composer test
```

## Documentation

The full guide, with more examples, lives at
[pingark.com/docs/laravel-plugin](https://pingark.com/docs/laravel-plugin).

## Contributing

Issues and pull requests are welcome. Before opening a pull request, please run the
test suite (`composer test`), format with Laravel Pint (`composer lint`), and run the
static analysis (`composer analyse`). The CI workflow runs all three on every push.

## License

Released under the MIT License. Copyright &copy; 2026 Virtueplanet Services LLP. See
[LICENSE](LICENSE) for details.
