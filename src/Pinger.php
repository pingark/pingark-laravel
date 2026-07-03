<?php

namespace PingArk\Laravel;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends slug-scheme pings to PingArk. Every send is wrapped so that
 * monitoring can NEVER break or slow the host job (PRD §5.7): a short
 * timeout, swallowed exceptions, and no retries (the next scheduled run
 * pings again). The named helpers below map one-to-one onto PingArk's ping
 * signals (Architecture.md §5.1).
 */
final class Pinger
{
    /**
     * Build the ping URL for a check slug and optional signal suffix.
     *
     * @param  string  $slug  the check slug under the project ping key
     * @param  string  $suffix  '' (success) | 'start' | 'fail' | 'log' | an exit code
     */
    public function url(string $slug, string $suffix = ''): string
    {
        $base = rtrim((string) config('pingark.base_url'), '/');
        $key = (string) config('pingark.ping_key');

        return "{$base}/ping/{$key}/{$slug}".($suffix === '' ? '' : "/{$suffix}");
    }

    /**
     * Signal that a job has started, so PingArk can measure its run duration.
     * A start ping never moves the deadline (Architecture.md §4.3).
     */
    public function start(?string $check = null): void
    {
        $this->send($this->resolve($check), 'start');
    }

    /**
     * Signal that a job finished successfully (re-arms the check). An optional
     * body is stored on the timeline (e.g. a short run summary).
     */
    public function success(?string $check = null, ?string $body = null): void
    {
        $this->send($this->resolve($check), '', $body);
    }

    /**
     * Signal that a job failed (sends the check straight to down). Pass the
     * caught exception to attach its message and stack trace as context, or a
     * plain string, or nothing.
     *
     * @param  string|Throwable|null  $context  exception or message to record
     */
    public function fail(?string $check = null, string|Throwable|null $context = null): void
    {
        $this->send($this->resolve($check), 'fail', $this->body($context));
    }

    /**
     * Report a process exit code. 0 counts as success (re-arms), any non-zero
     * code counts as a failure (Architecture.md §5.1), and the raw code is
     * recorded on the ping either way.
     *
     * @param  int  $code  the process exit status (0-999)
     * @param  string|Throwable|null  $context  optional exception or message
     */
    public function exitCode(?string $check, int $code, string|Throwable|null $context = null): void
    {
        $this->send($this->resolve($check), (string) $code, $this->body($context));
    }

    /**
     * Record a timeline event without changing the check's state (the /log
     * signal, Architecture.md §5.1): never arms, recovers, resumes, or alerts.
     * Useful for in-job progress notes such as "processed 5k of 20k rows".
     */
    public function log(?string $check = null, ?string $body = null): void
    {
        $this->send($this->resolve($check), 'log', $body);
    }

    /**
     * The management API client, for creating and configuring checks from code
     * (see {@see PingArkApi}). Reachable as PingArk::api() via the facade.
     */
    public function api(): PingArkApi
    {
        return app(PingArkApi::class);
    }

    /**
     * Fire one ping, optionally with a body (e.g. captured output on failure).
     * Silent on every failure by design, so monitoring can never break the job.
     *
     * @param  string  $slug  the check slug (empty slug is a no-op)
     * @param  string  $suffix  '' | 'start' | 'fail' | 'log' | exit code
     * @param  string|null  $body  optional text body, POSTed as text/plain
     */
    public function send(string $slug, string $suffix = '', ?string $body = null): void
    {
        // The fail-open guard: never ping when disabled, unconfigured, or the
        // check could not be resolved. A silent no-op is the correct behaviour.
        if ($slug === '' || ! config('pingark.enabled') || ! config('pingark.ping_key')) {
            return;
        }

        try {
            $request = Http::timeout((int) config('pingark.timeout', 5))
                ->withUserAgent((string) config('pingark.user_agent', 'PingArk-Laravel'));

            if ($body !== null && $body !== '') {
                // The server truncates to 100 KiB; we keep the client body sane too.
                $request->withBody(mb_substr($body, 0, 100 * 1024), 'text/plain')
                    ->post($this->url($slug, $suffix));
            } else {
                $request->get($this->url($slug, $suffix));
            }
        } catch (Throwable) {
            // Never let monitoring break the job being monitored (PRD §5.7).
        }
    }

    /**
     * Resolve the check slug to ping, falling back to the configured default
     * check when none is given. Returns an empty string when nothing resolves,
     * which send() treats as a silent no-op.
     */
    private function resolve(?string $check): string
    {
        return (string) ($check ?? config('pingark.default_check') ?? '');
    }

    /**
     * Normalise a body argument into a string. A Throwable is rendered as its
     * class, message, origin, and stack trace so failures carry real context.
     */
    private function body(string|Throwable|null $context): ?string
    {
        if ($context instanceof Throwable) {
            return sprintf(
                "%s: %s\n%s:%d\n%s",
                $context::class,
                $context->getMessage(),
                $context->getFile(),
                $context->getLine(),
                $context->getTraceAsString(),
            );
        }

        return $context;
    }
}
