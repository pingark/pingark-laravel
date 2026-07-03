<?php

namespace PingArk\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

/**
 * Derives a stable PingArk check slug for a scheduled event, so the same
 * task maps to the same check across deploys.
 */
final class SlugsEvents
{
    /** A slug for the event: its artisan command, description, or a hash. */
    public static function slugFor(Event $event): string
    {
        $command = (string) ($event->command ?? '');

        // "'/usr/bin/php' 'artisan' backup:run --quiet" -> "backup:run --quiet"
        // (artisan is single-quoted on unix, double-quoted on Windows).
        if ($command !== '' && preg_match('/artisan["\']?\s+(.*)$/', $command, $matches) === 1) {
            return Str::slug($matches[1]);
        }

        $description = (string) ($event->description ?? '');
        if ($description !== '') {
            return Str::slug($description);
        }

        // Last resort: stable hash of whatever identifies the event.
        return 'task-'.substr(sha1($command.$event->expression), 0, 8);
    }

    /** A human name for the event, used when sync creates checks. */
    public static function nameFor(Event $event): string
    {
        $command = (string) ($event->command ?? '');

        if ($command !== '' && preg_match('/artisan["\']?\s+(.*)$/', $command, $matches) === 1) {
            return $matches[1];
        }

        return (string) ($event->description ?: 'Scheduled task');
    }
}
