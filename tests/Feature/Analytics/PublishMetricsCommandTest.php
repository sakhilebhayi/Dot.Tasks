<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `tasks:publish-metrics`: one queued SendAnalyticsSnapshot per linked, non-personal company, with explicit team scoping,
 * aggregates only, nothing sent unless configured, and nothing here ever able to hurt the app.
 */
class PublishMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0001';

    private const UUID_A = '5b0f2c0e-6a6e-4a43-9d43-3f1c2f0a9e11';

    private const UUID_B = '9c1d7a52-1b0e-4c7d-8f0a-2d5e6b7c8d90';

    private const PLATFORM = 'tasks';

    /** Metric keys Dot.Analytics' config/metrics.php accepts for this platform (what this app can truthfully send is a subset). */
    private const CONTRACT_KEYS = ['open', 'overdue', 'completed_7d', 'created_7d'];

    private const SENTINEL_TEAM = 'ZqTeamSentinel-9f3a7c1e5b2d4086a1c3e5f70912b4d6';

    private const SENTINEL_ROW = 'ZqRowSentinel-1c8e5a7b3d9f40628e1a4c6b0d2f5973';

    private const SENTINEL_PERSON = 'ZqPersonSentinel-7d2b9e4a6c1f83052b7d9e1a3c5f6408';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 02:10:00');
        Cache::flush();
        config(['queue.default' => 'sync', 'services.analytics' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => self::PLATFORM, 'timeout' => 5]]);
        $this->fakeAnalytics([self::URL => Http::response(['data' => ['replayed' => false]], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeAnalytics(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }

    private function company(?string $uuid, bool $personal = false): Team
    {
        $owner = User::factory()->create(['name' => self::SENTINEL_PERSON, 'email' => 'zq-person-sentinel-'.uniqid().'@example.test']);

        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => self::SENTINEL_TEAM,
            'personal_team' => $personal,
            'infodot_team_uuid' => $uuid,
        ]);
    }

    /** $open counted tasks, plus rows that must never be counted or sent (archived, other status noise). */
    private function seedCompany(Team $team, int $open): void
    {
        $list = TaskList::create(['team_id' => $team->id, 'owner_id' => $team->user_id, 'name' => self::SENTINEL_ROW.' board']);
        for ($i = 0; $i < $open; $i++) {
            $task = new Task(['task_list_id' => $list->id, 'title' => self::SENTINEL_ROW.' task '.$i, 'description' => self::SENTINEL_ROW.' description', 'status' => 'todo', 'assignee_id' => $team->user_id]);
            $task->save();
        }
        $done = new Task(['task_list_id' => $list->id, 'title' => self::SENTINEL_ROW.' done', 'status' => 'done']);
        $done->save();
    }

    /** @return list<array<string, mixed>> the payloads of every job the command queued */
    private function queuedPayloads(): array
    {
        $payloads = [];
        Bus::assertDispatched(SendAnalyticsSnapshot::class, function (SendAnalyticsSnapshot $job) use (&$payloads) {
            $payloads[] = $job->payload;

            return true;
        });

        return $payloads;
    }

    // ------------------------------------------------------------- which companies

    public function test_one_job_is_queued_per_linked_company(): void
    {
        Bus::fake();
        $a = $this->company(self::UUID_A);
        $b = $this->company(self::UUID_B);
        $this->seedCompany($a, 2);
        $this->seedCompany($b, 5);

        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 2);
        $payloads = collect($this->queuedPayloads())->keyBy('company_uuid');
        $this->assertSame([self::UUID_A, self::UUID_B], $payloads->keys()->sort()->values()->all());
        $this->assertSame(2, $payloads[self::UUID_A]['metrics']['open']);
        $this->assertSame(5, $payloads[self::UUID_B]['metrics']['open'], 'company A numbers never include company B rows, and the other way round');
    }

    public function test_personal_and_unlinked_teams_are_skipped(): void
    {
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 1);
        $this->seedCompany($this->company('11111111-2222-4333-8444-555555555555', personal: true), 1);
        $this->seedCompany($this->company(null), 1);
        $this->seedCompany($this->company(''), 1);

        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 1);
        $this->assertSame(self::UUID_A, $this->queuedPayloads()[0]['company_uuid']);
    }

    public function test_the_company_option_publishes_just_that_company(): void
    {
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 1);
        $this->seedCompany($this->company(self::UUID_B), 1);

        $this->artisan('tasks:publish-metrics', ['--company' => self::UUID_B])->assertExitCode(0);

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 1);
        $this->assertSame(self::UUID_B, $this->queuedPayloads()[0]['company_uuid']);
    }

    public function test_an_unknown_or_personal_company_option_publishes_nothing(): void
    {
        Bus::fake();
        $this->company(self::UUID_A, personal: true);

        $this->artisan('tasks:publish-metrics', ['--company' => self::UUID_A])->assertExitCode(0);
        $this->artisan('tasks:publish-metrics', ['--company' => '00000000-0000-4000-8000-000000000000'])->assertExitCode(0);

        Bus::assertNotDispatched(SendAnalyticsSnapshot::class);
    }

    // ------------------------------------------------------------- the payload

    public function test_the_payload_has_exactly_the_contract_shape_and_only_contract_keys(): void
    {
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 3);

        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        $payload = $this->queuedPayloads()[0];
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($payload));
        $this->assertSame(self::PLATFORM, $payload['platform']);
        $this->assertSame(self::UUID_A, $payload['company_uuid']);
        $this->assertSame('2026-11-05T00:00:00Z', $payload['taken_at'], 'the start of the UTC day');
        $this->assertSame('day', $payload['period']);
        $this->assertSame(['open', 'overdue', 'created_7d'], array_keys($payload['metrics']));
        $this->assertSame([], array_diff(array_keys($payload['metrics']), self::CONTRACT_KEYS), 'only keys Dot.Analytics accepts');
        foreach ($payload['metrics'] as $key => $value) {
            $this->assertTrue(is_int($value) || is_float($value), "{$key} is a plain number");
            $this->assertGreaterThanOrEqual(0, $value, "{$key} is not negative");
        }

    }

    public function test_taken_at_is_the_utc_day_so_a_resend_the_same_day_is_replay_safe(): void
    {
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 1);

        $this->artisan('tasks:publish-metrics');
        Carbon::setTestNow('2026-11-05 23:59:59');
        $this->artisan('tasks:publish-metrics');
        Carbon::setTestNow('2026-11-06 00:00:00');
        $this->artisan('tasks:publish-metrics');

        $taken = collect($this->queuedPayloads())->pluck('taken_at')->all();
        $this->assertSame(['2026-11-05T00:00:00Z', '2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'], $taken);
    }

    public function test_taken_at_is_utc_whatever_the_server_zone(): void
    {
        Bus::fake();
        date_default_timezone_set('Africa/Johannesburg');
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-05 01:30:00', 'Africa/Johannesburg')); // still 4 Nov, 23:30 UTC
            $this->seedCompany($this->company(self::UUID_A), 1);

            $this->artisan('tasks:publish-metrics');

            $this->assertSame('2026-11-04T00:00:00Z', $this->queuedPayloads()[0]['taken_at']);
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_no_row_level_data_reaches_the_queue_or_the_wire(): void
    {
        $a = $this->company(self::UUID_A);
        $this->seedCompany($a, 2);

        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->body();
            foreach ([self::SENTINEL_TEAM, self::SENTINEL_ROW, self::SENTINEL_PERSON] as $needle) {
                if (str_contains($body, $needle) || str_contains(strtolower($body), 'sentinel')) {
                    return false;
                }
            }
            $decoded = json_decode($body, true);

            return array_keys($decoded) === ['platform', 'company_uuid', 'taken_at', 'period', 'metrics']
                && strlen($body) < 600;
        });
    }

    public function test_the_numbers_do_not_depend_on_who_is_signed_in(): void
    {
        Bus::fake();
        $a = $this->company(self::UUID_A);
        $b = $this->company(self::UUID_B);
        $this->seedCompany($a, 2);
        $this->seedCompany($b, 5);
        $outsider = $b->owner;
        $outsider->forceFill(['current_team_id' => $b->id])->save();

        $this->actingAs($outsider);
        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        $payloads = collect($this->queuedPayloads())->keyBy('company_uuid');
        $this->assertSame(2, $payloads[self::UUID_A]['metrics']['open']);
        $this->assertSame(5, $payloads[self::UUID_B]['metrics']['open']);
    }

    // ------------------------------------------------------------- configuration and dry run

    public function test_nothing_is_sent_or_queued_while_the_url_or_secret_is_blank(): void
    {
        $this->seedCompany($this->company(self::UUID_A), 1);

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['services.analytics' => array_merge(config('services.analytics'), $blank)]);
            Bus::fake();

            $this->artisan('tasks:publish-metrics')->expectsOutputToContain('disabled')->assertExitCode(0);

            Bus::assertNotDispatched(SendAnalyticsSnapshot::class);
        }
        Http::assertNothingSent();
    }

    public function test_a_dry_run_prints_the_payload_and_sends_and_queues_nothing(): void
    {
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 4);

        $this->assertSame(0, Artisan::call('tasks:publish-metrics', ['--dry-run' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString(self::UUID_A, $output);
        $this->assertStringContainsString('"open": 4', $output);
        $this->assertStringContainsString('2026-11-05T00:00:00Z', $output);
        $this->assertStringNotContainsString(self::SENTINEL_ROW, $output);

        Bus::assertNotDispatched(SendAnalyticsSnapshot::class);
        Http::assertNothingSent();
    }

    public function test_a_dry_run_works_with_no_configuration_and_never_prints_the_secret(): void
    {
        config(['services.analytics' => ['url' => null, 'secret' => null, 'platform' => self::PLATFORM, 'timeout' => 5]]);
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 1);

        $this->assertSame(0, Artisan::call('tasks:publish-metrics', ['--dry-run' => true]));

        $this->assertStringContainsString(self::UUID_A, Artisan::output());
        $this->assertStringNotContainsString(self::SECRET, Artisan::output());
        Bus::assertNotDispatched(SendAnalyticsSnapshot::class);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- end to end, and Analytics misbehaving

    public function test_each_company_is_posted_once_with_a_verifiable_signature(): void
    {
        $this->seedCompany($this->company(self::UUID_A), 2);
        $this->seedCompany($this->company(self::UUID_B), 1);

        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        Http::assertSentCount(2);
        $seen = [];
        Http::assertSent(function (Request $request) use (&$seen) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';
            $valid = ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET);
            $seen[] = json_decode($request->body(), true)['company_uuid'];

            return $valid && $request->url() === self::URL;
        });
        sort($seen);
        $this->assertSame([self::UUID_A, self::UUID_B], $seen);
    }

    public function test_sending_the_same_day_twice_posts_identical_bodies_so_analytics_can_dedupe(): void
    {
        $this->seedCompany($this->company(self::UUID_A), 2);

        $this->artisan('tasks:publish-metrics');
        Carbon::setTestNow('2026-11-05 20:00:00');
        $this->artisan('tasks:publish-metrics');

        $bodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]->body())->unique();
        $this->assertCount(1, $bodies);
    }

    public function test_an_analytics_outage_never_fails_the_command_or_anything_else(): void
    {
        $this->seedCompany($this->company(self::UUID_A), 1);
        $this->seedCompany($this->company(self::UUID_B), 1);

        foreach ([Http::response('boom', 500), Http::response('slow', 429), fn () => throw new ConnectionException('cURL error 28: timed out'), Http::response('no', 401), fn () => throw new \RuntimeException('kaboom')] as $failure) {
            $this->fakeAnalytics([self::URL => $failure]);
            Cache::flush();

            $this->artisan('tasks:publish-metrics')->assertExitCode(0);
        }
    }

    public function test_the_circuit_breaker_stops_a_flood_of_calls_to_a_failing_analytics(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->seedCompany($this->company('00000000-0000-4000-8000-0000000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)), 1);
        }
        $this->fakeAnalytics([self::URL => Http::response('boom', 500)]);

        $this->artisan('tasks:publish-metrics')->assertExitCode(0);

        Http::assertSentCount(5);
    }

    public function test_one_company_that_cannot_be_computed_does_not_stop_the_others(): void
    {
        Bus::fake();
        $this->seedCompany($this->company(self::UUID_A), 1);
        $this->seedCompany($this->company(self::UUID_B), 1);
        $real = app(MetricsCollector::class);
        $calls = 0;
        $flaky = \Mockery::mock(MetricsCollector::class);
        $flaky->shouldReceive('collect')->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('database hiccup');
            }

            return $real->collect(...$args);
        });
        $this->app->instance(MetricsCollector::class, $flaky);

        $this->artisan('tasks:publish-metrics')->assertExitCode(1);

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 1);
    }

    public function test_a_queue_that_cannot_accept_the_job_does_not_throw_into_the_scheduler(): void
    {
        $this->seedCompany($this->company(self::UUID_A), 1);
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend is down'));

        $this->artisan('tasks:publish-metrics')->assertExitCode(1);
    }

    // ------------------------------------------------------------- scheduling

    public function test_it_is_scheduled_daily_at_a_quiet_hour_in_utc(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'tasks:publish-metrics'));

        $this->assertNotNull($event, 'tasks:publish-metrics must be on the scheduler');
        $this->assertSame('10 2 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
    }
}
