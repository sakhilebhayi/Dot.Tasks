<?php

namespace App\Jobs;

use App\Services\Analytics\AnalyticsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one company's daily snapshot to Dot.Analytics off the request path. Retries (3 tries, with backoff)
 * while Analytics is down, gives up quietly on a refusal, and never throws: a missed snapshot must not break
 * anything, and the next day's run sends a fresh one.
 * Needs a queue worker (php artisan queue:work); with QUEUE_CONNECTION=sync it runs inline.
 */
class SendAnalyticsSnapshot implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    /**
     * The payload is fixed when the command queues the job, so every retry re-sends the same company, platform,
     * taken_at and period; the signature and timestamp are made at send time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * Longer than the circuit breaker's one minute pause, so a retry after an outage finds it closed.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AnalyticsClient $client): void
    {
        try {
            $outcome = $client->deliver($this->payload);
        } catch (Throwable) {
            $outcome = AnalyticsClient::RETRY;
        }

        if ($outcome !== AnalyticsClient::RETRY) {
            return;
        }

        try {
            if ($this->job && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)] ?? 60);

                return;
            }

            if ($this->job) {
                Log::warning('Dot.Analytics snapshot abandoned after repeated failures.', ['platform' => $this->payload['platform'] ?? null, 'company' => $this->payload['company_uuid'] ?? null]);
            }
        } catch (Throwable) {
            // Nothing a metrics snapshot does may fail the worker.
        }
    }
}
