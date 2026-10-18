<?php

namespace Tests\Feature;

use App\Jobs\SendNotifyEvent;
use App\Livewire\Tasks\CreateTask;
use App\Livewire\Tasks\EscalationQueue;
use App\Livewire\Tasks\TaskDetail;
use App\Models\TaskEscalationProposal;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Services\Notify\NotifyClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * Dot.Tasks tells Dot.Notify about the events it already raises in-app. Notify is a shared service: nothing
 * here may ever break or slow a user action, and nothing is sent unless it is configured.
 */
class NotifyEventsTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'notify-ingest-secret-for-tests-0001';

    private const UUID = '5b0f2c0e-6a6e-4a43-9d43-3f1c2f0a9e11';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        Cache::flush();
        config(['services.notify' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'tasks', 'timeout' => 5]]);
        URL::forceRootUrl('http://tasks.infodot.co.za'); // as a dev .env would have it: the client must still send https
        $this->fakeNotify([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Replaces any earlier stub (Http::fake only ever adds, and the first match wins) and clears the record. */
    private function fakeNotify(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }

    private function linked(): object
    {
        $f = $this->teamFixture();
        $f->team->forceFill(['infodot_team_uuid' => self::UUID])->save();
        $f->list->update(['name' => 'Launch board']);
        $f->task->update(['title' => 'Draft the launch plan']);

        return $f;
    }

    /** @return array<string, mixed> */
    private function sentJson(int $index = 0): array
    {
        $recorded = Http::recorded()->values()->all();

        return json_decode($recorded[$index][0]->body(), true);
    }

    private function assign(object $f, string $by, ?User $to = null): void
    {
        Livewire::actingAs($f->{$by})
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->call('assignTask', ($to ?? $f->editor)->id);
    }

    private function assertCommonShape(array $json, string $event, string $email, array $dataKeys): void
    {
        $this->assertSame(['company_uuid', 'data', 'event', 'idempotency_key', 'platform', 'recipient'], $this->sorted(array_keys($json)));
        $this->assertSame('tasks', $json['platform']);
        $this->assertSame(self::UUID, $json['company_uuid']);
        $this->assertSame($event, $json['event']);
        $this->assertSame(['email' => $email], $json['recipient']);
        $this->assertSame($this->sorted($dataKeys), $this->sorted(array_keys($json['data'])), 'only the catalogue keys');
        $this->assertLessThanOrEqual(120, strlen($json['idempotency_key']));
        $this->assertStringStartsWith("{$event}:", $json['idempotency_key']);
        $this->assertStringContainsString(':'.sha1($email).':', $json['idempotency_key']);

        $url = $json['data']['url'];
        $this->assertStringStartsWith('https://', $url);
        $this->assertSame('tasks.infodot.co.za', parse_url($url, PHP_URL_HOST));
        $this->assertSame(1, substr_count(json_encode($json), $email), 'the address appears once, in recipient.email only');
    }

    /** @param  list<string>  $a @return list<string> */
    private function sorted(array $a): array
    {
        sort($a);

        return $a;
    }

    // ------------------------------------------------------------------ events

    public function test_assigning_a_task_posts_a_signed_task_assigned_event(): void
    {
        $f = $this->linked();
        $f->task->update(['due_date' => '2026-11-12']);

        $this->assign($f, 'owner');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)
                && $timestamp === (string) now()->timestamp
                && ! str_contains($body, self::SECRET);
        });

        $json = $this->sentJson();
        $this->assertCommonShape($json, 'task.assigned', $f->editor->email, ['task_title', 'board_name', 'assigned_by', 'due_date', 'url']);
        $this->assertSame('Draft the launch plan', $json['data']['task_title']);
        $this->assertSame('Launch board', $json['data']['board_name']);
        $this->assertSame('Olivia Owner Fixture', $json['data']['assigned_by']);
        $this->assertSame('2026-11-12', $json['data']['due_date']);
        $this->assertSame('https://tasks.infodot.co.za/lists/'.$f->list->id, $json['data']['url']);
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        $this->fakeNotify(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 202);
        });
        $f = $this->linked();

        $this->assign($f, 'owner');

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    public function test_creating_a_task_assigned_to_someone_else_posts_task_assigned(): void
    {
        $f = $this->linked();

        Livewire::actingAs($f->owner)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Brand new task')
            ->set('assigneeId', $f->editor->id)
            ->call('save')
            ->assertHasNoErrors();

        Http::assertSentCount(1);
        $json = $this->sentJson();
        $this->assertCommonShape($json, 'task.assigned', $f->editor->email, ['task_title', 'board_name', 'assigned_by', 'due_date', 'url']);
        $this->assertSame('Brand new task', $json['data']['task_title']);
        $this->assertSame('', $json['data']['due_date'], 'a task with no due date sends an empty string');
    }

    public function test_assigning_a_task_to_yourself_sends_nothing(): void
    {
        $f = $this->linked();

        $this->assign($f, 'editor', $f->editor);
        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Mine')
            ->set('assigneeId', $f->editor->id)
            ->call('save');

        Http::assertNothingSent();
        $this->assertSame(0, $f->editor->notifications()->count(), 'no in-app notification either: unchanged behaviour');
    }

    public function test_the_due_soon_command_posts_task_due_soon_to_the_assignee(): void
    {
        $f = $this->linked();
        $f->task->update(['assignee_id' => $f->editor->id, 'due_date' => '2026-11-06']);

        $this->artisan('tasks:check-due-soon')->assertSuccessful();

        Http::assertSentCount(1);
        $json = $this->sentJson();
        $this->assertCommonShape($json, 'task.due_soon', $f->editor->email, ['task_title', 'board_name', 'due_date', 'url']);
        $this->assertSame('2026-11-06', $json['data']['due_date']);
        $this->assertSame('task.due_soon:'.$f->task->id.':'.sha1($f->editor->email).':2026-11-06', $json['idempotency_key']);
        $this->assertSame(1, $f->editor->notifications()->where('type', TaskDueSoonNotification::class)->count());

        // The command's own 24 hour guard still applies: a second run adds nothing, in-app or email.
        $this->artisan('tasks:check-due-soon')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_approving_an_escalation_posts_task_escalated_to_the_assignee(): void
    {
        $f = $this->linked();
        $f->task->update(['assignee_id' => $f->editor->id, 'due_date' => now()->subDays(8)]);
        $proposal = TaskEscalationProposal::create(['task_id' => $f->task->id, 'status' => 'pending', 'reason' => 'Overdue by 8 days, internal reason text.']);

        Livewire::actingAs($f->owner)->test(EscalationQueue::class)->call('approve', $proposal->id);

        $this->assertSame('approved', $proposal->fresh()->status);
        Http::assertSentCount(1);
        $json = $this->sentJson();
        $this->assertCommonShape($json, 'task.escalated', $f->editor->email, ['task_title', 'board_name', 'owner_name', 'url']);
        $this->assertSame('Olivia Owner Fixture', $json['data']['owner_name']);
        $this->assertSame('task.escalated:'.$f->task->id.':'.sha1($f->editor->email).':'.$proposal->id, $json['idempotency_key']);
        $this->assertStringNotContainsString('internal reason text', Http::recorded()[0][0]->body());
    }

    public function test_an_escalation_with_no_assignee_or_a_self_assigned_owner_sends_nothing(): void
    {
        $f = $this->linked();
        $proposal = TaskEscalationProposal::create(['task_id' => $f->task->id, 'status' => 'pending', 'reason' => 'x']);
        Livewire::actingAs($f->owner)->test(EscalationQueue::class)->call('approve', $proposal->id);
        $this->assertSame('approved', $proposal->fresh()->status);

        $other = $f->list->tasks()->create(['title' => 'Owner task', 'status' => 'todo', 'assignee_id' => $f->owner->id]);
        $second = TaskEscalationProposal::create(['task_id' => $other->id, 'status' => 'pending', 'reason' => 'x']);
        Livewire::actingAs($f->owner)->test(EscalationQueue::class)->call('approve', $second->id);

        Http::assertNothingSent();
    }

    public function test_a_mention_posts_task_mentioned_to_the_mentioned_member_only_and_never_the_comment_text(): void
    {
        $f = $this->linked();
        $f->task->update(['description' => 'Confidential description sentence zebra', 'assignee_id' => $f->admin->id]);

        Livewire::actingAs($f->editor)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->set('commentBody', 'Quokka secret remark, please look @Viewer Person Fixture')
            ->call('addComment')
            ->assertHasNoErrors();

        $this->assertSame(1, $f->viewer->notifications()->where('type', MentionedInCommentNotification::class)->count());
        Http::assertSentCount(1);
        $json = $this->sentJson();
        $this->assertCommonShape($json, 'task.mentioned', $f->viewer->email, ['task_title', 'board_name', 'mentioned_by', 'url']);
        $this->assertSame('Editor Person Fixture', $json['data']['mentioned_by']);

        $raw = Http::recorded()[0][0]->body();
        $this->assertStringNotContainsString('Quokka', $raw);
        $this->assertStringNotContainsString('zebra', $raw);
        $this->assertStringNotContainsString($f->admin->email, $raw, 'the assignee only gets the in-app new-comment notice, not a mention email');
    }

    public function test_mentioning_yourself_or_a_non_member_sends_nothing(): void
    {
        $f = $this->linked();

        Livewire::actingAs($f->editor)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->set('commentBody', 'Note to self @Editor Person Fixture and @Otto Outsider Fixture')
            ->call('addComment');

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- when not to send

    public function test_it_is_disabled_when_the_url_or_the_secret_is_blank(): void
    {
        $f = $this->linked();
        Log::spy();

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['services.notify' => array_merge(config('services.notify'), $blank)]);
            $this->assertFalse(app(NotifyClient::class)->enabled());
            $this->assign($f, 'owner');
            $this->assign($f, 'owner', $f->admin);
        }
        $this->assertSame(3, $f->editor->notifications()->count(), 'in-app notifications are unaffected when Notify is off');

        Http::assertNothingSent();
        Log::shouldHaveReceived('debug')->withArgs(fn ($message) => str_contains($message, 'disabled'));

        Queue::fake();
        $this->assign($f, 'owner');
        Queue::assertNothingPushed();
    }

    public function test_a_team_not_linked_to_infodot_is_skipped(): void
    {
        $f = $this->linked();
        $f->team->forceFill(['infodot_team_uuid' => null])->save();

        $this->assign($f, 'owner');

        Http::assertNothingSent();
        $this->assertSame(1, $f->editor->notifications()->where('type', TaskAssignedNotification::class)->count(), 'in-app still sent');
    }

    public function test_a_recipient_with_no_email_is_skipped(): void
    {
        $f = $this->linked();
        $f->editor->forceFill(['email' => ''])->save();

        $this->assign($f, 'owner');

        Http::assertNothingSent();
        $this->assertSame(1, $f->editor->notifications()->count());
    }

    // ------------------------------------------------------------- Notify misbehaving

    /** @return array<string, array{0: callable}> */
    public static function failures(): array
    {
        return [
            '500' => [fn () => Http::response('boom', 500)],
            '429' => [fn () => Http::response('slow down', 429)],
            '401' => [fn () => Http::response('no', 401)],
            'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'unexpected exception' => [fn () => fn () => throw new \RuntimeException('kaboom')],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_notify_failure_changes_neither_the_user_action_nor_the_in_app_notification(callable $failure): void
    {
        $this->fakeNotify([self::URL => $failure()]);
        $f = $this->linked();

        $this->assign($f, 'owner');

        $this->assertSame($f->editor->id, $f->task->fresh()->assignee_id);
        $this->assertSame(1, $f->editor->notifications()->where('type', TaskAssignedNotification::class)->count());
    }

    public function test_a_failure_to_even_queue_the_event_never_reaches_the_caller(): void
    {
        $f = $this->linked();
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down')));

        $this->assign($f, 'owner');

        $this->assertSame($f->editor->id, $f->task->fresh()->assignee_id);
        $this->assertSame(1, $f->editor->notifications()->count());
    }

    public function test_the_event_is_queued_after_commit_and_not_sent_inside_the_request(): void
    {
        Queue::fake();
        $f = $this->linked();

        $this->assign($f, 'owner');

        Queue::assertPushed(SendNotifyEvent::class, 1);
        Http::assertNothingSent();

        $job = new SendNotifyEvent(['event' => 'x']);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_the_job_never_throws_whatever_the_client_does(): void
    {
        $this->fakeNotify(fn () => throw new \RuntimeException('unexpected'));

        (new SendNotifyEvent(['platform' => 'tasks', 'event' => 'task.assigned']))->handle(app(NotifyClient::class));

        $broken = \Mockery::mock(NotifyClient::class);
        $broken->shouldReceive('deliver')->andThrow(new \RuntimeException('client exploded'));
        (new SendNotifyEvent(['event' => 'task.assigned']))->handle($broken);

        $this->assertTrue(true);
    }

    public function test_the_job_asks_the_queue_to_retry_with_backoff_then_stops_after_three_tries(): void
    {
        $this->fakeNotify([self::URL => Http::response('x', 503)]);
        $payload = ['platform' => 'tasks', 'event' => 'task.assigned', 'company_uuid' => self::UUID, 'recipient' => ['email' => 'a@b.test'], 'data' => [], 'idempotency_key' => 'k'];

        $release = [];
        foreach ([1, 2, 3] as $attempt) {
            $queueJob = \Mockery::mock(QueueJob::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $queueJob->shouldReceive('release')->andReturnUsing(function ($delay) use (&$release) {
                $release[] = $delay;
            });
            $job = new SendNotifyEvent($payload);
            $job->setJob($queueJob);
            $job->handle(app(NotifyClient::class));
        }

        $this->assertSame([60, 300], $release, 'released twice with backoff; the third failure is abandoned, not released');
    }

    public function test_a_permanent_refusal_is_not_retried(): void
    {
        $this->fakeNotify([self::URL => Http::response('nope', 422)]);
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->never();
        $job = new SendNotifyEvent(['platform' => 'tasks', 'event' => 'task.assigned']);
        $job->setJob($queueJob);

        $job->handle(app(NotifyClient::class));

        Http::assertSentCount(1);
    }

    public function test_outcomes_for_transient_and_permanent_responses(): void
    {
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'tasks', 'event' => 'task.assigned'];
        $this->fakeNotify(['*' => Http::sequence()->push('x', 503)->push('x', 429)->push('x', 422)->push([], 200)->pushFailedConnection()]);

        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload));
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'tasks', 'event' => 'task.assigned', 'idempotency_key' => 'k'];

        $client->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(10));
        $client->deliver($payload);

        $stamps = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-InfoDot-Timestamp')[0])->all();
        $this->assertCount(2, array_unique($stamps));
    }

    // ------------------------------------------------------------- circuit breaker

    public function test_the_circuit_opens_after_five_consecutive_failures_and_closes_again(): void
    {
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'tasks', 'event' => 'task.assigned'];

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        }
        Http::assertSentCount(5);

        // Open: the next calls do not touch Notify at all, and ask for a later retry.
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        Http::assertSentCount(5);

        // After 60 seconds one probe goes out; Notify is back, so the circuit closes.
        Carbon::setTestNow(now()->addSeconds(61));
        $this->fakeNotify([self::URL => Http::response([], 202)]);
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertCount(2, Http::recorded(), 'closed again: both calls went out');

        // Closing reset the count: four failures in a row do not reopen it.
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        for ($i = 0; $i < 4; $i++) {
            $client->deliver($payload);
        }
        Http::assertSentCount(4);
        $this->fakeNotify([self::URL => Http::response([], 202)]);
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
    }

    public function test_a_failed_probe_reopens_the_circuit_for_another_minute(): void
    {
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'tasks', 'event' => 'task.assigned'];
        for ($i = 0; $i < 5; $i++) {
            $client->deliver($payload);
        }

        Carbon::setTestNow(now()->addSeconds(61));
        $client->deliver($payload); // the probe, fails
        $client->deliver($payload); // open again: no call
        $client->deliver($payload);

        Http::assertSentCount(6);
    }

    public function test_a_permanent_refusal_does_not_count_towards_the_circuit(): void
    {
        $this->fakeNotify([self::URL => Http::response('no', 401)]);
        $client = app(NotifyClient::class);

        for ($i = 0; $i < 8; $i++) {
            $client->deliver(['platform' => 'tasks', 'event' => 'task.assigned']);
        }

        Http::assertSentCount(8);
    }

    public function test_an_open_circuit_leaves_the_user_action_and_in_app_notification_untouched(): void
    {
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $f = $this->linked();
        for ($i = 0; $i < 5; $i++) {
            app(NotifyClient::class)->deliver(['platform' => 'tasks', 'event' => 'task.assigned']);
        }

        $this->assign($f, 'owner');

        $this->assertSame($f->editor->id, $f->task->fresh()->assignee_id);
        $this->assertSame(1, $f->editor->notifications()->count());
        Http::assertSentCount(5);
    }

    // ------------------------------------------------------------- idempotency and privacy

    public function test_the_idempotency_key_is_stable_across_retries_and_double_dispatch_but_not_across_reassignment(): void
    {
        $f = $this->linked();
        $client = app(NotifyClient::class);

        $first = $client->taskAssignedPayload($f->task->fresh(), $f->editor, $f->owner);
        Carbon::setTestNow(now()->addHours(3)); // a retry hours later builds nothing new: the payload is fixed at dispatch
        $again = $client->taskAssignedPayload($f->task->fresh(), $f->editor, $f->owner);
        $this->assertSame($first['idempotency_key'], $again['idempotency_key'], 'same task state, same key');
        $this->assertMatchesRegularExpression('/^task\.assigned:\d+:[a-f0-9]{40}:\d+$/', $first['idempotency_key']);

        $f->task->touch();
        $later = $client->taskAssignedPayload($f->task->fresh(), $f->editor, $f->owner);
        $this->assertNotSame($first['idempotency_key'], $later['idempotency_key'], 'a genuine later assignment is a new event');
    }

    public function test_the_same_recipient_and_event_collapse_but_different_recipients_do_not(): void
    {
        $f = $this->linked();
        $client = app(NotifyClient::class);

        $a = $client->taskAssignedPayload($f->task->fresh(), $f->editor, $f->owner);
        $b = $client->taskAssignedPayload($f->task->fresh(), $f->admin, $f->owner);

        $this->assertNotSame($a['idempotency_key'], $b['idempotency_key']);
    }

    public function test_log_lines_contain_no_email_addresses_and_no_secret(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $f = $this->linked();

        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $this->assign($f, 'owner');
        $this->fakeNotify([self::URL => fn () => throw new ConnectionException('timeout')]);
        $this->assign($f, 'owner', $f->admin);
        $this->fakeNotify([self::URL => Http::response('no', 422)]);
        $this->assign($f, 'owner', $f->viewer);
        $f->team->forceFill(['infodot_team_uuid' => null])->save();
        $this->assign($f, 'owner', $f->editor);
        config(['services.notify.secret' => '']);
        $this->assign($f, 'owner', $f->admin);

        $this->assertNotEmpty($logged);
        $all = implode("\n", $logged);
        foreach ([$f->owner, $f->editor, $f->admin, $f->viewer] as $user) {
            $this->assertStringNotContainsString($user->email, $all);
        }
        $this->assertStringNotContainsString(self::SECRET, $all);
    }

    public function test_the_job_payload_never_holds_a_task_description_or_comment_body(): void
    {
        $f = $this->linked();
        $f->task->update(['description' => 'Description sentence giraffe']);
        Queue::fake();

        $this->assign($f, 'owner');

        Queue::assertPushed(SendNotifyEvent::class, function (SendNotifyEvent $job) {
            return ! str_contains(json_encode($job->payload), 'giraffe');
        });
    }
}
