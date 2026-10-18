<?php

namespace App\Services\Analytics;

use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes one company's daily aggregate snapshot to Dot.Analytics (POST /api/ingest/snapshots).
 *
 * Analytics is a shared service, so this is built to be harmless when things go wrong: snapshots are queued (never
 * sent inside a request), the HTTP call has a 5 second timeout and never follows a redirect, nothing here ever
 * throws into the caller, a small circuit breaker stops calling a failing Analytics for a minute, and with no
 * ANALYTICS_INGEST_URL or ANALYTICS_INGEST_SECRET it does nothing at all. Nothing in this app reads anything back
 * from Analytics, so an outage or a misconfiguration changes nothing that a user sees.
 *
 * Signing is the same scheme as Dot.Notify's: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's
 * secret, sent as "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. It is made at
 * SEND time, so a retry carries a fresh timestamp. The body is fixed when the job is queued, so a retry (or a
 * second run the same day) re-sends the same company, platform, taken_at and period, which Analytics treats as
 * the same snapshot.
 *
 * Privacy: a payload is {platform, company_uuid, taken_at, period, metrics}. The metrics are counts and totals
 * only; no names, emails, titles, reasons or any other row-level data are ever sent. Log lines carry the platform,
 * the company uuid and a status, never the body or the secret.
 */
class AnalyticsClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    /** Consecutive transient failures before the circuit opens. */
    private const FAILURE_THRESHOLD = 5;

    /** Seconds the circuit stays open. */
    private const PAUSE_SECONDS = 60;

    /** A run of failures older than this no longer counts as consecutive. */
    private const FAILURE_WINDOW_SECONDS = 300;

    private const KEY_FAILURES = 'analytics:circuit:failures';

    private const KEY_OPEN_UNTIL = 'analytics:circuit:open-until';

    public function enabled(): bool
    {
        return filled(config('services.analytics.url')) && filled(config('services.analytics.secret'));
    }

    public function platformKey(): string
    {
        return (string) (config('services.analytics.platform') ?: 'tasks');
    }

    /** A company is a linked, non-personal team. Personal teams are one person's workspace, not a company. */
    public function publishable(Team $team): bool
    {
        return ! $team->personal_team && filled($team->infodot_team_uuid);
    }

    /**
     * The contract body for one company on one UTC day, or null when there is nothing truthful to say
     * (not a linked company, or no metric could be computed).
     *
     * @param  array<string, int|float>  $metrics
     * @return array{platform: string, company_uuid: string, taken_at: string, period: string, metrics: array<string, int|float>}|null
     */
    public function payload(Team $team, array $metrics, CarbonInterface $now): ?array
    {
        if (! $this->publishable($team) || $metrics === []) {
            return null;
        }

        return [
            'platform' => $this->platformKey(),
            'company_uuid' => (string) $team->infodot_team_uuid,
            'taken_at' => $now->copy()->utc()->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $metrics,
        ];
    }

    /**
     * POST one snapshot now and say what the caller (the queued job) should do next. Never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        $context = ['platform' => $payload['platform'] ?? null, 'company' => $payload['company_uuid'] ?? null];

        if (! $this->enabled()) {
            Log::debug('Dot.Analytics is disabled (ANALYTICS_INGEST_URL or ANALYTICS_INGEST_SECRET is blank); snapshot not sent.', $context);

            return self::GIVE_UP;
        }

        if ($this->circuitOpen()) {
            Log::info('Dot.Analytics circuit is open; the snapshot will be retried later.', $context);

            return self::RETRY;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('services.analytics.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withoutRedirecting()
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.analytics.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('services.analytics.url'));
        } catch (ConnectionException) {
            $this->recordFailure();
            Log::warning('Dot.Analytics unreachable; will retry.', $context);

            return self::RETRY;
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('Dot.Analytics call failed unexpectedly; will retry.', $context + ['error' => class_basename($e)]);

            return self::RETRY;
        }

        $status = $response->status();

        // 201 stored, 200 already held (a replay of the same company, platform, taken_at and period): both are done.
        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            $this->recordFailure();
            Log::warning('Dot.Analytics unavailable; will retry.', $context + ['status' => $status]);

            return self::RETRY;
        }

        // 401/403: our secret does not match Analytics' (or this platform is not connected there); 404: the company
        // is unknown to Analytics; 422: the contract was broken; 3xx: the URL is wrong. Retrying cannot fix any of
        // these and none says anything about Analytics' health, so they do not count towards the circuit.
        Log::error('Dot.Analytics refused the snapshot.', $context + ['status' => $status]);

        return self::GIVE_UP;
    }

    private function circuitOpen(): bool
    {
        try {
            return (int) Cache::get(self::KEY_OPEN_UNTIL, 0) > now()->timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(): void
    {
        try {
            $failures = (int) Cache::get(self::KEY_FAILURES, 0) + 1;
            Cache::put(self::KEY_FAILURES, $failures, self::FAILURE_WINDOW_SECONDS);

            if ($failures >= self::FAILURE_THRESHOLD) {
                // The count is kept, so the first call after the pause is a probe: one more failure reopens it.
                Cache::put(self::KEY_OPEN_UNTIL, now()->timestamp + self::PAUSE_SECONDS, self::PAUSE_SECONDS);
            }
        } catch (Throwable) {
            // The breaker is a courtesy to Analytics; losing it must not matter.
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::KEY_FAILURES);
            Cache::forget(self::KEY_OPEN_UNTIL);
        } catch (Throwable) {
        }
    }
}
