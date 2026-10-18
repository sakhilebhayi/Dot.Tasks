<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Analytics\AnalyticsClient;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Delivery of one snapshot to Dot.Analytics. Analytics is a shared service: whatever it does (down, slow, rate
 * limiting, refusing us, misconfigured) must never throw into this app, and nothing is sent unless both the URL
 * and the secret are configured.
 */
class AnalyticsClientTest extends TestCase
{
    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0001';

    private const UUID = '5b0f2c0e-6a6e-4a43-9d43-3f1c2f0a9e11';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 02:10:00');
        Cache::flush();
        config(['services.analytics' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'tasks', 'timeout' => 5]]);
        $this->fakeAnalytics([self::URL => Http::response(['data' => ['replayed' => false]], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Replaces any earlier stub (Http::fake only ever adds, and the first match wins) and clears the record. */
    private function fakeAnalytics(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'platform' => 'tasks',
            'company_uuid' => self::UUID,
            'taken_at' => '2026-11-05T00:00:00Z',
            'period' => 'day',
            'metrics' => ['open' => 12, 'overdue' => 3, 'created_7d' => 5],
        ];
    }

    public function test_it_posts_a_signed_json_snapshot_to_the_configured_url(): void
    {
        $payload = $this->payload();

        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($payload));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($payload) {
            $body = $request->body();
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && str_starts_with($request->header('Content-Type')[0] ?? '', 'application/json')
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)
                && $timestamp === (string) now()->timestamp
                && json_decode($body, true) === $payload
                && ! str_contains($body, self::SECRET);
        });
    }

    public function test_a_tampered_body_does_not_verify_against_the_signature(): void
    {
        app(AnalyticsClient::class)->deliver($this->payload());

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $tampered = str_replace(self::UUID, 'ffffffff-6a6e-4a43-9d43-3f1c2f0a9e11', $request->body());

            return ($request->header('X-InfoDot-Signature')[0] ?? '') !== 'sha256='.hash_hmac('sha256', $timestamp.'.'.$tampered, self::SECRET);
        });
    }

    public function test_the_request_uses_a_five_second_timeout_and_never_follows_redirects(): void
    {
        $seen = null;
        $this->fakeAnalytics(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 201);
        });

        app(AnalyticsClient::class)->deliver($this->payload());

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
        $this->assertFalse((bool) ($seen['allow_redirects'] ?? true), 'a signed body must not be re-sent to wherever a redirect points');
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        $client = app(AnalyticsClient::class);
        $payload = $this->payload();

        $client->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(10));
        $client->deliver($payload);

        $stamps = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-InfoDot-Timestamp')[0])->all();
        $this->assertCount(2, array_unique($stamps));
        $bodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]->body())->all();
        $this->assertCount(1, array_unique($bodies), 'the body (and so taken_at) is identical on a retry');
    }

    public function test_it_is_disabled_when_the_url_or_the_secret_is_blank(): void
    {
        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['services.analytics' => array_merge(config('services.analytics'), $blank)]);

            $this->assertFalse(app(AnalyticsClient::class)->enabled());
            $this->assertSame(AnalyticsClient::GIVE_UP, app(AnalyticsClient::class)->deliver($this->payload()));
        }

        Http::assertNothingSent();
    }

    public static function failures(): array
    {
        return [
            '500' => [fn () => Http::response('boom', 500)],
            '503' => [fn () => Http::response('down', 503)],
            '429' => [fn () => Http::response('slow down', 429)],
            'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'unexpected exception' => [fn () => fn () => throw new \RuntimeException('kaboom')],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_transient_analytics_failure_is_a_retry_and_never_throws(callable $failure): void
    {
        $this->fakeAnalytics([self::URL => $failure()]);

        $this->assertSame(AnalyticsClient::RETRY, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    #[DataProvider('refusals')]
    public function test_a_permanent_refusal_is_given_up_not_retried(int $status): void
    {
        $this->fakeAnalytics([self::URL => Http::response('no', $status)]);

        $this->assertSame(AnalyticsClient::GIVE_UP, app(AnalyticsClient::class)->deliver($this->payload()));
        Http::assertSentCount(1);
    }

    public static function refusals(): array
    {
        return ['401 bad signature' => [401], '403' => [403], '404 unknown company' => [404], '422 contract broken' => [422], '302 redirect' => [302]];
    }

    public function test_outcomes_in_sequence(): void
    {
        $client = app(AnalyticsClient::class);
        $this->fakeAnalytics(['*' => Http::sequence()->push('x', 503)->push('x', 429)->push('x', 422)->push([], 200)->push([], 201)->pushFailedConnection()]);

        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($this->payload()));
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()), '200 is a replay Analytics already holds: done');
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
    }

    // ------------------------------------------------------------- circuit breaker

    public function test_the_circuit_opens_after_five_consecutive_failures_and_closes_again(): void
    {
        $this->fakeAnalytics([self::URL => Http::response('boom', 500)]);
        $client = app(AnalyticsClient::class);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        }
        Http::assertSentCount(5);

        // Open: the next calls do not touch Analytics at all, and ask for a later retry.
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()));
        Http::assertSentCount(5);

        // After 60 seconds one probe goes out; Analytics is back, so the circuit closes.
        Carbon::setTestNow(now()->addSeconds(61));
        $this->fakeAnalytics([self::URL => Http::response([], 201)]);
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()));
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()));
        $this->assertCount(2, Http::recorded(), 'closed again: both calls went out');

        // Closing reset the count: four failures in a row do not reopen it.
        $this->fakeAnalytics([self::URL => Http::response('boom', 500)]);
        for ($i = 0; $i < 4; $i++) {
            $client->deliver($this->payload());
        }
        Http::assertSentCount(4);
        $this->fakeAnalytics([self::URL => Http::response([], 201)]);
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($this->payload()));
    }

    public function test_a_failed_probe_reopens_the_circuit_for_another_minute(): void
    {
        $this->fakeAnalytics([self::URL => Http::response('boom', 500)]);
        $client = app(AnalyticsClient::class);
        for ($i = 0; $i < 5; $i++) {
            $client->deliver($this->payload());
        }

        Carbon::setTestNow(now()->addSeconds(61));
        $client->deliver($this->payload()); // the probe, fails
        $client->deliver($this->payload()); // open again: no call
        $client->deliver($this->payload());

        Http::assertSentCount(6);
    }

    public function test_a_permanent_refusal_does_not_count_towards_the_circuit(): void
    {
        $this->fakeAnalytics([self::URL => Http::response('no', 401)]);
        $client = app(AnalyticsClient::class);

        for ($i = 0; $i < 8; $i++) {
            $client->deliver($this->payload());
        }

        Http::assertSentCount(8);
    }

    public function test_a_broken_cache_cannot_break_delivery(): void
    {
        Cache::shouldReceive('get', 'put', 'forget')->andThrow(new \RuntimeException('cache down'));
        $this->fakeAnalytics([self::URL => Http::response('boom', 500)]);

        $this->assertSame(AnalyticsClient::RETRY, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    // ------------------------------------------------------------- the queued job

    public function test_the_job_never_throws_whatever_the_client_does(): void
    {
        $this->fakeAnalytics(fn () => throw new \RuntimeException('unexpected'));
        (new SendAnalyticsSnapshot($this->payload()))->handle(app(AnalyticsClient::class));

        $broken = \Mockery::mock(AnalyticsClient::class);
        $broken->shouldReceive('deliver')->andThrow(new \RuntimeException('client exploded'));
        (new SendAnalyticsSnapshot($this->payload()))->handle($broken);

        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andThrow(new \RuntimeException('queue exploded'));
        $job = new SendAnalyticsSnapshot($this->payload());
        $job->setJob($queueJob);
        $job->handle($broken);

        $this->assertTrue(true);
    }

    public function test_the_job_asks_the_queue_to_retry_with_backoff_then_stops_after_three_tries(): void
    {
        $this->fakeAnalytics([self::URL => Http::response('x', 503)]);

        $release = [];
        foreach ([1, 2, 3] as $attempt) {
            $queueJob = \Mockery::mock(QueueJob::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $queueJob->shouldReceive('release')->andReturnUsing(function ($delay) use (&$release) {
                $release[] = $delay;
            });
            $job = new SendAnalyticsSnapshot($this->payload());
            $job->setJob($queueJob);
            $job->handle(app(AnalyticsClient::class));
        }

        $this->assertSame([60, 300], $release, 'released twice with backoff; the third failure is abandoned, not released');
        $this->assertSame(3, (new SendAnalyticsSnapshot($this->payload()))->tries);
        $this->assertSame([60, 300], (new SendAnalyticsSnapshot($this->payload()))->backoff());
    }

    public function test_a_permanent_refusal_is_not_retried_by_the_job(): void
    {
        $this->fakeAnalytics([self::URL => Http::response('nope', 422)]);
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->never();
        $job = new SendAnalyticsSnapshot($this->payload());
        $job->setJob($queueJob);

        $job->handle(app(AnalyticsClient::class));

        Http::assertSentCount(1);
    }

    public function test_the_job_carries_its_payload_unchanged_so_a_retry_resends_the_same_day(): void
    {
        $job = new SendAnalyticsSnapshot($this->payload());
        $copy = unserialize(serialize($job));

        $this->assertSame($this->payload(), $copy->payload);
    }

    public function test_log_lines_contain_no_secret_and_no_body(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        foreach ([Http::response('boom', 500), fn () => throw new ConnectionException('timeout'), Http::response('no', 422), Http::response('no', 401)] as $failure) {
            $this->fakeAnalytics([self::URL => $failure]);
            Cache::flush();
            app(AnalyticsClient::class)->deliver($this->payload());
        }
        config(['services.analytics.secret' => '']);
        app(AnalyticsClient::class)->deliver($this->payload());

        $all = implode("\n", $logged);
        $this->assertNotSame('', trim($all));
        $this->assertStringNotContainsString(self::SECRET, $all);
        $this->assertStringNotContainsString('sha256=', $all);
        $this->assertStringNotContainsString('"metrics"', $all);
    }
}
