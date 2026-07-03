<?php

namespace PingArk\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PingArk\Laravel\Pinger;

/**
 * Static entry point to the PingArk client, backing the {@see Pinger}
 * singleton. Use it to send ping signals from anywhere in your app, for
 * example inside a job or an exception handler:
 *
 *   PingArk::start('nightly-backup');
 *   PingArk::success('nightly-backup');
 *   PingArk::fail('nightly-backup', $exception);
 *   PingArk::log('import', 'processed 5k of 20k rows');
 *
 * @method static string url(string $slug, string $suffix = '')
 * @method static void start(?string $check = null)
 * @method static void success(?string $check = null, ?string $body = null)
 * @method static void fail(?string $check = null, string|\Throwable|null $context = null)
 * @method static void exitCode(?string $check, int $code, string|\Throwable|null $context = null)
 * @method static void log(?string $check = null, ?string $body = null)
 * @method static void send(string $slug, string $suffix = '', ?string $body = null)
 * @method static \PingArk\Laravel\PingArkApi api()
 *
 * @see Pinger
 */
class PingArk extends Facade
{
    /**
     * Resolve the facade to the shared Pinger singleton.
     */
    protected static function getFacadeAccessor(): string
    {
        return Pinger::class;
    }
}
