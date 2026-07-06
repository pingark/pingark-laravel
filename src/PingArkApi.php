<?php

namespace PingArk\Laravel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin client for the PingArk management API (Architecture.md §10): create,
 * read, configure, pause, resume, and delete checks, and list their pings,
 * flips, and the project's channels.
 *
 * Unlike {@see Pinger}, this client is NOT on the monitoring hot path, so it
 * deliberately throws on any HTTP error (every call ends in ->throw()). It is
 * for setup and tooling, where a failure should be surfaced, not swallowed.
 * Authenticate with a read-write project API key (config `pingark.api_key`).
 */
class PingArkApi
{
    /**
     * List every check in the key's project.
     *
     * @return array<int, array<string, mixed>> the serialized check objects
     */
    public function checks(): array
    {
        return $this->request()->get('/checks')->throw()->json('checks', []);
    }

    /**
     * Fetch a single check by id.
     *
     * @param  string  $id  the check uuid
     * @return array<string, mixed> the serialized check
     */
    public function check(string $id): array
    {
        return $this->request()->get("/checks/{$id}")->throw()->json('check', []);
    }

    /**
     * Create a check. See the CheckRequest field set (name, slug, schedule_type,
     * period/schedule_expr, timezone, grace, tags, channels, filtering rules).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the created check
     */
    public function createCheck(array $attributes): array
    {
        return $this->request()->post('/checks', $attributes)->throw()->json('check', []);
    }

    /**
     * Update a check. Only the keys you pass are changed; omitting `channels`
     * or `tags` leaves those attachments untouched (a partial update).
     *
     * @param  string  $id  the check uuid
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the updated check
     */
    public function updateCheck(string $id, array $attributes): array
    {
        return $this->request()->put("/checks/{$id}", $attributes)->throw()->json('check', []);
    }

    /**
     * Delete a check permanently.
     *
     * @param  string  $id  the check uuid
     */
    public function deleteCheck(string $id): void
    {
        $this->request()->delete("/checks/{$id}")->throw();
    }

    /**
     * Pause a check (stops it expecting pings and alerting) until resumed.
     *
     * @param  string  $id  the check uuid
     */
    public function pause(string $id): void
    {
        $this->request()->post("/checks/{$id}/pause")->throw();
    }

    /**
     * Resume a paused check.
     *
     * @param  string  $id  the check uuid
     */
    public function resume(string $id): void
    {
        $this->request()->post("/checks/{$id}/resume")->throw();
    }

    /**
     * List a check's recent pings, newest first.
     *
     * @param  string  $id  the check uuid
     * @param  int  $limit  how many to return (1-1000)
     * @return array<int, array<string, mixed>>
     */
    public function pings(string $id, int $limit = 100): array
    {
        return $this->request()->get("/checks/{$id}/pings", ['limit' => $limit])
            ->throw()->json('pings', []);
    }

    /**
     * Fetch the raw text body a single ping carried.
     *
     * @param  string  $id  the check uuid
     * @param  int  $pingId  the ping id (from the pings list)
     * @return string the stored body as plain text
     */
    public function pingBody(string $id, int $pingId): string
    {
        return $this->request()->get("/checks/{$id}/pings/{$pingId}/body")->throw()->body();
    }

    /**
     * List a check's status-change history (flips), newest first.
     *
     * @param  string  $id  the check uuid
     * @param  int  $limit  how many to return (1-1000)
     * @return array<int, array<string, mixed>>
     */
    public function flips(string $id, int $limit = 100): array
    {
        return $this->request()->get("/checks/{$id}/flips", ['limit' => $limit])
            ->throw()->json('flips', []);
    }

    /**
     * List the project's notification channels, so you can discover the ids to
     * attach to a check on create or update.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channels(): array
    {
        return $this->request()->get('/channels')->throw()->json('channels', []);
    }

    /**
     * Build a pre-authenticated request against /api/v1, carrying the API key
     * as a bearer token and the plugin's user agent.
     *
     * @throws RuntimeException when no API key is configured
     */
    private function request(): PendingRequest
    {
        $apiKey = (string) config('pingark.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Set PINGARK_API_KEY (a read-write project key) to use the PingArk management API.');
        }

        $base = rtrim((string) config('pingark.api_url'), '/');

        return Http::baseUrl("{$base}/api/v1")
            ->withToken($apiKey)
            ->withUserAgent((string) config('pingark.user_agent', 'PingArk-Laravel'))
            ->acceptJson();
    }
}
