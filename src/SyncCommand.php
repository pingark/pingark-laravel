<?php

namespace PingArk\Laravel;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

/**
 * Registers the application's entire schedule as PingArk checks via the
 * management API (the wedge's "auto-register scheduled tasks" promise): one
 * check per scheduled event, keyed by slug, with the cron expression and
 * timezone mirrored from the scheduler. Existing checks are left untouched, so
 * the command is safe to run repeatedly.
 */
class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'pingark:sync
        {--dry-run : List what would be created without creating it}
        {--prune : Also report checks that no longer map to a scheduled task (never deletes)}';

    /** @var string */
    protected $description = 'Register all scheduled tasks as PingArk checks';

    /**
     * Mirror the schedule into PingArk.
     *
     * @return int a console exit code
     */
    public function handle(Schedule $schedule): int
    {
        if ((string) config('pingark.api_key') === '') {
            $this->error('Set PINGARK_API_KEY (a read-write project key) to sync.');
            // Onboarding: this failure is the first thing a fresh
            // `composer require` user sees, so point at the free account
            // and the key they need.
            $this->line('No PingArk account yet? Create your free project at https://pingark.com/register, then generate a read-write key under API keys.');

            return self::FAILURE;
        }

        $api = app(PingArkApi::class);
        $existing = (new Collection($api->checks()))->keyBy('slug');

        $created = 0;
        $skipped = 0;
        $scheduled = [];

        foreach ($schedule->events() as $event) {
            /** @var Event $event */
            $slug = SlugsEvents::slugFor($event);
            $scheduled[$slug] = true;

            if ($existing->has($slug)) {
                $skipped++;

                continue;
            }

            $payload = [
                'name' => SlugsEvents::nameFor($event),
                'slug' => $slug,
                'schedule_type' => 'cron',
                'schedule_expr' => $event->expression,
                'timezone' => (string) ($event->timezone ?: config('app.timezone', 'UTC')),
                'grace' => (int) config('pingark.default_grace', 600),
            ];

            if ($this->option('dry-run')) {
                $this->line("would create: {$slug} ({$event->expression})");
                $created++;

                continue;
            }

            $api->createCheck($payload);
            $this->info("created: {$slug} ({$event->expression})");
            $created++;
        }

        $this->info("sync complete: {$created} created, {$skipped} already present");
        $this->line('See these checks on your dashboard: https://pingark.com/app/checks');

        if ($this->option('prune')) {
            $this->reportOrphans($existing, $scheduled);
        }

        $this->comment('Add ->pingArk() to your scheduled tasks so they ping these checks.');

        return self::SUCCESS;
    }

    /**
     * List PingArk checks whose slug no longer matches any scheduled task. This
     * only reports; it never deletes, so a check you still want (paused, or
     * pinged another way) is never removed behind your back.
     *
     * @param  Collection<string, array<string, mixed>>  $existing  checks in PingArk, keyed by slug
     * @param  array<string, bool>  $scheduled  slugs present in the current schedule
     */
    private function reportOrphans(Collection $existing, array $scheduled): void
    {
        $orphans = $existing->keys()->reject(fn (string $slug): bool => isset($scheduled[$slug]));

        if ($orphans->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('These PingArk checks no longer map to a scheduled task (not deleted):');

        foreach ($orphans as $slug) {
            $this->line("  - {$slug}");
        }
    }
}
