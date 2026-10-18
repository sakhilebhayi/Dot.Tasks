<?php

namespace App\Services\Notify;

use App\Jobs\SendNotifyEvent;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskEscalationProposal;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Dot.Notify "this happened to this company, tell this person" (POST /api/ingest/events) for the events
 * Dot.Tasks already raises in-app: task.assigned, task.due_soon, task.escalated and task.mentioned.
 *
 * Notify is a shared service, so this is built to be harmless when things go wrong: events are queued after the
 * surrounding database transaction commits (never sent inside the user's request), the HTTP call has a 5 second
 * timeout, nothing here ever throws into the caller, a small circuit breaker stops calling a failing Notify for
 * a minute, and with no NOTIFY_INGEST_URL or NOTIFY_INGEST_SECRET it does nothing at all. The in-app
 * (database) notifications are written by their own code paths and never depend on any of this.
 *
 * Signing is Notify's scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's secret, sent as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. It is made at SEND time, so a
 * retry carries a fresh timestamp instead of a stale one.
 *
 * Privacy: a payload carries only the catalogue keys for its event. No comment text, no task description, and
 * an email address appears only in `recipient.email`. Log lines never contain an address or the secret.
 *
 * Idempotency key: "{event}:{subject id}:{sha1(recipient email)}:{bucket}". The bucket is fixed when the event is
 * raised, so retries and double dispatches collapse in Notify while a genuinely later event does not.
 */
class NotifyClient
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

    private const KEY_FAILURES = 'notify:circuit:failures';

    private const KEY_OPEN_UNTIL = 'notify:circuit:open-until';

    public function enabled(): bool
    {
        return filled(config('services.notify.url')) && filled(config('services.notify.secret'));
    }

    // ------------------------------------------------------------------ events (all of these never throw)

    /** Someone else assigned `$assignee` a task. The caller has already excluded self-assignment. */
    public function taskAssigned(Task $task, User $assignee, ?User $assignedBy): void
    {
        $this->queue('task.assigned', fn () => $this->taskAssignedPayload($task, $assignee, $assignedBy));
    }

    public function taskDueSoon(Task $task, User $assignee): void
    {
        $this->queue('task.due_soon', fn () => $this->taskDueSoonPayload($task, $assignee));
    }

    public function taskEscalated(Task $task, TaskEscalationProposal $proposal, User $recipient): void
    {
        $this->queue('task.escalated', fn () => $this->taskEscalatedPayload($task, $proposal, $recipient));
    }

    public function taskMentioned(Task $task, TaskComment $comment, User $mentioned, User $author): void
    {
        $this->queue('task.mentioned', fn () => $this->taskMentionedPayload($task, $comment, $mentioned, $author));
    }

    // ------------------------------------------------------------------ payloads

    /** @return array<string, mixed>|null */
    public function taskAssignedPayload(Task $task, User $assignee, ?User $assignedBy): ?array
    {
        $list = $task->taskList;

        return $this->payload('task.assigned', $list, $assignee, [
            'task_title' => (string) $task->title,
            'board_name' => (string) $list?->name,
            'assigned_by' => (string) ($assignedBy?->name ?? ''),
            'due_date' => $task->due_date?->toDateString() ?? '',
            'url' => $this->boardUrl($list),
        ], (string) $task->id, (string) ($task->updated_at?->timestamp ?? 0));
    }

    /** @return array<string, mixed>|null */
    public function taskDueSoonPayload(Task $task, User $assignee): ?array
    {
        $list = $task->taskList;
        $due = $task->due_date?->toDateString() ?? '';

        return $this->payload('task.due_soon', $list, $assignee, [
            'task_title' => (string) $task->title,
            'board_name' => (string) $list?->name,
            'due_date' => $due,
            'url' => $this->boardUrl($list),
        ], (string) $task->id, $due);
    }

    /** @return array<string, mixed>|null */
    public function taskEscalatedPayload(Task $task, TaskEscalationProposal $proposal, User $recipient): ?array
    {
        $list = $task->taskList;

        return $this->payload('task.escalated', $list, $recipient, [
            'task_title' => (string) $task->title,
            'board_name' => (string) $list?->name,
            'owner_name' => (string) ($list?->owner?->name ?? ''),
            'url' => $this->boardUrl($list),
        ], (string) $task->id, (string) $proposal->id);
    }

    /** @return array<string, mixed>|null */
    public function taskMentionedPayload(Task $task, TaskComment $comment, User $mentioned, User $author): ?array
    {
        $list = $task->taskList;

        return $this->payload('task.mentioned', $list, $mentioned, [
            'task_title' => (string) $task->title,
            'board_name' => (string) $list?->name,
            'mentioned_by' => (string) $author->name,
            'url' => $this->boardUrl($list),
        ], (string) $comment->id, (string) $task->id);
    }

    // ------------------------------------------------------------------ delivery (runs in the queued job)

    /**
     * POST one event now and say what the caller (the queued job) should do next. Never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        if ($this->circuitOpen()) {
            Log::info('Dot.Notify circuit is open; the event will be retried later.', ['event' => $payload['event'] ?? null]);

            return self::RETRY;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('services.notify.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('services.notify.url'));
        } catch (ConnectionException $e) {
            $this->recordFailure();
            Log::warning('Dot.Notify unreachable; will retry.', ['event' => $payload['event'] ?? null]);

            return self::RETRY;
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('Dot.Notify call failed unexpectedly; will retry.', ['event' => $payload['event'] ?? null, 'error' => class_basename($e)]);

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            $this->recordFailure();
            Log::warning('Dot.Notify unavailable; will retry.', ['event' => $payload['event'] ?? null, 'status' => $status]);

            return self::RETRY;
        }

        // 401/403: our secret is wrong; 404: the company is unknown; 422: the payload is bad. Retrying cannot fix
        // these and they say nothing about Notify's health, so they do not count towards the circuit.
        Log::error('Dot.Notify refused the event.', ['event' => $payload['event'] ?? null, 'status' => $status]);

        return self::GIVE_UP;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Build and queue one event. Never throws, whatever goes wrong building or queueing it.
     *
     * @param  \Closure(): (array<string, mixed>|null)  $build
     */
    private function queue(string $event, \Closure $build): void
    {
        try {
            if (! $this->enabled()) {
                Log::debug('Dot.Notify is disabled (NOTIFY_INGEST_URL or NOTIFY_INGEST_SECRET is blank); event not sent.', ['event' => $event]);

                return;
            }

            $payload = $build();

            if ($payload === null) {
                Log::debug('Dot.Notify event skipped: the team is not linked to InfoDot or the recipient has no email.', ['event' => $event]);

                return;
            }

            app(Dispatcher::class)->dispatch(new SendNotifyEvent($payload));
        } catch (Throwable $e) {
            Log::warning('Could not queue a Dot.Notify event.', ['event' => $event, 'error' => class_basename($e)]);
        }
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>|null null when the company or the person cannot be addressed
     */
    private function payload(string $event, ?TaskList $list, User $recipient, array $data, string $subject, string $bucket): ?array
    {
        $uuid = $list?->team?->infodot_team_uuid;
        $email = $recipient->email;

        if (! filled($uuid) || ! filled($email)) {
            return null;
        }

        return [
            'platform' => (string) config('services.notify.platform', 'tasks'),
            'company_uuid' => (string) $uuid,
            'event' => $event,
            'recipient' => ['email' => $email],
            'data' => $data,
            'idempotency_key' => $event.':'.$subject.':'.sha1(strtolower(trim($email))).':'.$bucket,
        ];
    }

    /** Absolute https URL of the board in this app (the same page the in-app notification links to). */
    private function boardUrl(?TaskList $list): string
    {
        if (! $list) {
            return '';
        }

        return preg_replace('#^http://#i', 'https://', route('task-lists.show', $list)) ?? '';
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
            // The breaker is a courtesy to Notify; losing it must not matter.
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
