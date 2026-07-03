<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PingArk\Laravel\Facades\PingArk;
use PingArk\Laravel\PingArkApi;
use PingArk\Laravel\Pinger;
use PingArk\Laravel\SlugsEvents;

describe('the pinger (never breaks the host job)', function () {
    it('builds slug-scheme ping urls', function () {
        $pinger = app(Pinger::class);

        expect($pinger->url('backup-run'))->toBe('https://pingark.test/ping/projkey123/backup-run')
            ->and($pinger->url('backup-run', 'start'))->toBe('https://pingark.test/ping/projkey123/backup-run/start');
    });

    it('sends success, start and fail pings', function () {
        Http::fake();
        $pinger = app(Pinger::class);

        $pinger->send('backup-run');
        $pinger->send('backup-run', 'start');
        $pinger->send('backup-run', 'fail', 'exit 137: out of memory');

        Http::assertSent(fn ($r) => $r->url() === 'https://pingark.test/ping/projkey123/backup-run');
        Http::assertSent(fn ($r) => $r->url() === 'https://pingark.test/ping/projkey123/backup-run/start');
        Http::assertSent(fn ($r) => $r->url() === 'https://pingark.test/ping/projkey123/backup-run/fail'
            && str_contains($r->body(), 'out of memory'));
    });

    it('sends the configured user agent so pings are recognisable', function () {
        Http::fake();
        config(['pingark.user_agent' => 'PingArk-Laravel/9.9']);

        app(Pinger::class)->send('backup-run');

        Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', 'PingArk-Laravel/9.9'));
    });

    it('sentinel: swallows transport failures so jobs never break', function () {
        Http::fake(fn () => throw new ConnectionException('refused'));

        app(Pinger::class)->send('backup-run');

        expect(true)->toBeTrue(); // reaching here IS the assertion
    });

    it('stays silent when disabled or unconfigured', function () {
        Http::fake();
        config(['pingark.enabled' => false]);

        app(Pinger::class)->send('backup-run');

        Http::assertNothingSent();
    });
});

describe('the PingArk facade signals', function () {
    it('sends start, success, log and exit-code signals to the right urls', function () {
        Http::fake();

        PingArk::start('job');
        PingArk::success('job');
        PingArk::log('job', 'processed 5k of 20k rows');
        PingArk::exitCode('job', 137);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/job/start'));
        Http::assertSent(fn ($r) => $r->url() === 'https://pingark.test/ping/projkey123/job');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/job/log')
            && str_contains($r->body(), 'processed 5k of 20k rows'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/job/137'));
    });

    it('attaches exception class and message as failure context', function () {
        Http::fake();

        PingArk::fail('job', new RuntimeException('disk full'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/job/fail')
            && str_contains($r->body(), 'RuntimeException')
            && str_contains($r->body(), 'disk full'));
    });

    it('falls back to the configured default check when none is given', function () {
        Http::fake();
        config(['pingark.default_check' => 'the-only-job']);

        PingArk::success();

        Http::assertSent(fn ($r) => $r->url() === 'https://pingark.test/ping/projkey123/the-only-job');
    });

    it('is a silent no-op when no check resolves', function () {
        Http::fake();

        PingArk::success(); // no argument, no default_check

        Http::assertNothingSent();
    });

    it('swallows transport errors from a log ping like the other signals', function () {
        Http::fake(fn () => throw new ConnectionException('refused'));

        PingArk::log('job', 'a progress note');

        expect(true)->toBeTrue();
    });
});

describe('slug derivation', function () {
    it('derives stable slugs from artisan commands', function () {
        $schedule = app(Schedule::class);
        $event = $schedule->command('backup:run --quiet')->dailyAt('02:00');

        expect(SlugsEvents::slugFor($event))->toBe('backuprun-quiet')
            ->and(SlugsEvents::nameFor($event))->toContain('backup:run');
    });

    it('uses the description for closures', function () {
        $schedule = app(Schedule::class);
        $event = $schedule->call(fn () => null)->name('warm caches')->everyMinute();

        expect(SlugsEvents::slugFor($event))->toBe('warm-caches');
    });
});

describe('the ->pingArk() scheduler macro (the wedge)', function () {
    it('pings start and success around a scheduled run', function () {
        Http::fake();

        $schedule = app(Schedule::class);
        $event = $schedule->call(fn () => null)->name('heartbeat')->everyMinute()->pingArk();

        $event->run(app());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/heartbeat/start'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/heartbeat'));
    });

    it('pings fail when the task throws', function () {
        Http::fake();

        $schedule = app(Schedule::class);
        $event = $schedule->call(function (): void {
            throw new RuntimeException('boom');
        })->name('exploder')->everyMinute()->pingArk();

        try {
            $event->run(app());
        } catch (Throwable) {
            // the scheduler would log this; the ping still fires.
        }

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping/projkey123/exploder/fail'));
    });

    it('honours an explicit slug', function () {
        Http::fake();

        $schedule = app(Schedule::class);
        $event = $schedule->call(fn () => null)->name('whatever')->everyMinute()->pingArk('custom-slug');

        $event->run(app());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/custom-slug'));
    });
});

describe('the management API client (PingArk::api())', function () {
    it('creates a check with bearer auth through /api/v1', function () {
        Http::fake(['pingark.test/api/v1/*' => Http::response(['check' => ['id' => 'new-id']], 201)]);

        $check = PingArk::api()->createCheck([
            'name' => 'Nightly', 'slug' => 'nightly', 'schedule_type' => 'simple',
            'period' => 3600, 'grace' => 600, 'timezone' => 'UTC',
        ]);

        expect($check['id'])->toBe('new-id');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://pingark.test/api/v1/checks'
            && $r->hasHeader('Authorization', 'Bearer pa_test_key')
            && $r['slug'] === 'nightly');
    });

    it('pauses and resumes a check', function () {
        Http::fake(['pingark.test/api/v1/*' => Http::response([], 200)]);

        $api = app(PingArkApi::class);
        $api->pause('abc');
        $api->resume('abc');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/checks/abc/pause'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/checks/abc/resume'));
    });

    it('lists pings, flips and channels', function () {
        Http::fake([
            'pingark.test/api/v1/checks/abc/pings*' => Http::response(['pings' => [['id' => 1]]]),
            'pingark.test/api/v1/checks/abc/flips*' => Http::response(['flips' => [['from' => 'up']]]),
            'pingark.test/api/v1/channels' => Http::response(['channels' => [['id' => 7]]]),
        ]);

        $api = app(PingArkApi::class);

        expect($api->pings('abc'))->toHaveCount(1)
            ->and($api->flips('abc'))->toHaveCount(1)
            ->and($api->channels())->toHaveCount(1);
    });

    it('throws when no api key is configured', function () {
        config(['pingark.api_key' => null]);

        expect(fn () => app(PingArkApi::class)->checks())->toThrow(RuntimeException::class);
    });
});

describe('pingark:sync', function () {
    it('creates missing checks through the management API and skips existing ones', function () {
        Http::fake([
            'pingark.test/api/v1/checks' => Http::sequence()
                ->push(['checks' => [['slug' => 'backuprun', 'id' => 'x']]])
                ->whenEmpty(Http::response(['check' => ['id' => 'new']], 201)),
        ]);

        $schedule = app(Schedule::class);
        $schedule->command('backup:run')->dailyAt('02:00');
        $schedule->command('reports:send')->weeklyOn(1, '08:00');

        $this->artisan('pingark:sync')
            ->expectsOutputToContain('created: reportssend')
            ->assertSuccessful();

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['slug'] === 'reportssend'
            && $r['schedule_type'] === 'cron'
            && $r['schedule_expr'] === '0 8 * * 1'
            && $r['grace'] === 600);
    });

    it('reports orphaned checks with --prune without deleting them', function () {
        Http::fake([
            'pingark.test/api/v1/checks' => Http::response(['checks' => [['slug' => 'gone-away', 'id' => 'x']]]),
        ]);

        $schedule = app(Schedule::class);
        $schedule->command('backup:run')->dailyAt('02:00');

        $this->artisan('pingark:sync --prune')
            ->expectsOutputToContain('gone-away')
            ->assertSuccessful();

        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    });

    it('refuses to run without an api key', function () {
        config(['pingark.api_key' => null]);

        $this->artisan('pingark:sync')->assertFailed();
    });
});
