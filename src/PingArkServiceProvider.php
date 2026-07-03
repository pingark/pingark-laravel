<?php

namespace PingArk\Laravel;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The PingArk Laravel plugin (the product's wedge): adds a ->pingArk() macro to
 * scheduled events that pings start/success/failure around every run (durations
 * and failure context come for free on the PingArk side), a pingark:sync command
 * that registers the whole schedule as checks, and the PingArk facade for sending
 * ping signals by hand from anywhere in your app.
 */
class PingArkServiceProvider extends ServiceProvider
{
    /** Merge default config and register the client singletons. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pingark.php', 'pingark');

        $this->app->singleton(Pinger::class);
        $this->app->singleton(PingArkApi::class);
    }

    /** Publish config, register the macro and the sync command. */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pingark.php' => config_path('pingark.php'),
        ], 'pingark-config');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class]);
        }

        $this->registerScheduleMacro();
    }

    /**
     * Register ->pingArk() on any scheduled event: /start before the run, plain
     * success after, and a failure ping on failure. The Pinger never throws, so
     * jobs are never affected (PRD §5.7).
     */
    private function registerScheduleMacro(): void
    {
        Event::macro('pingArk', function (?string $slug = null): Event {
            /** @var Event $this */
            $slug ??= SlugsEvents::slugFor($this);
            $pinger = app(Pinger::class);

            $this->before(function () use ($pinger, $slug): void {
                $pinger->start($slug);
            });

            $this->onSuccess(function () use ($pinger, $slug): void {
                $pinger->success($slug);
            });

            $this->onFailure(function () use ($pinger, $slug): void {
                // Attach the captured output file, when the task wrote one, so
                // the failure carries real context on the PingArk timeline.
                $body = $this->output !== null && is_file((string) $this->output)
                    ? (string) file_get_contents((string) $this->output)
                    : null;

                // A real command that exits non-zero reports its actual code, so
                // an OOM kill (137) is distinguishable from a generic failure.
                // Callbacks only ever synthesise exit code 1, so they send the
                // plain /fail signal instead.
                if (is_int($this->exitCode) && $this->exitCode > 0 && ! $this instanceof CallbackEvent) {
                    $pinger->exitCode($slug, $this->exitCode, $body);
                } else {
                    $pinger->fail($slug, $body);
                }
            });

            return $this;
        });
    }
}
