<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskRule;
use App\Models\TaskRuleRun;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use App\Support\Today;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs a team's rules. A rule acts as the person who wrote it, through the same services and the same permission checks
 * as a person, so it can never do what they could not: blockers, approvals and roles all still hold. Each event runs a
 * rule once. Inboxes are private, so no rule touches them.
 */
class Automations
{
    private const MOST_ATTEMPTS = 3;

    private const DEEPEST = 3;

    /** Rules running now: one rule's actions can set off another, but not itself, and not for ever. @var list<int> */
    private static array $running = [];

    /** Something happened. Failures here are recorded on the run and never reach the person who did the thing. */
    public function fire(string $trigger, Task $task, string $key): void
    {
        DB::afterCommit(function () use ($trigger, $task, $key) {
            try {
                $this->fireNow($trigger, $task, $key);
            } catch (Throwable $e) {
                Log::error('automation.fire', ['trigger' => $trigger, 'task' => $task->id, 'error' => $e->getMessage()]);
            }
        });
    }

    private function fireNow(string $trigger, Task $task, string $key): void
    {
        $board = $task->taskList;
        if (! $board || $board->is_inbox || count(self::$running) >= self::DEEPEST) {
            return;
        }

        $rules = TaskRule::where('team_id', $board->team_id)->where('trigger', $trigger)->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('task_list_id')->orWhere('task_list_id', $board->id))
            ->whereNotIn('id', self::$running)
            ->get();

        foreach ($rules as $rule) {
            if (! $this->fits($rule, $task)) {
                continue;
            }

            $run = $this->open($rule, $task, $key);
            if ($run?->status === 'waiting' && $run->run_at->lte(now())) {
                $this->perform($run);
            }
        }
    }

    /** One run for one event, or nothing if this event already has one. */
    private function open(TaskRule $rule, Task $task, string $key): ?TaskRuleRun
    {
        try {
            return DB::transaction(fn () => TaskRuleRun::create([
                'task_rule_id' => $rule->id,
                'task_id' => $task->id,
                'event_key' => $key,
                'status' => $rule->needs_approval ? 'needs_approval' : 'waiting',
                'run_at' => now()->addMinutes($rule->delay_minutes),
                'attempts' => 0,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function fits(TaskRule $rule, Task $task): bool
    {
        if ($rule->conditions === []) {
            return true;
        }

        $results = array_map(fn (array $row) => $this->holds($row, $task), $rule->conditions);

        return $rule->match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /** @param array{field: string, op: string, value: string} $row */
    private function holds(array $row, Task $task): bool
    {
        if ($row['field'] === 'label') {
            $has = $task->labels()->pluck('labels.id')->map(fn (int $id) => (string) $id);

            return match ($row['op']) {
                'is', 'contains' => $has->contains($row['value']),
                'is_not' => ! $has->contains($row['value']),
                'empty' => $has->isEmpty(),
                'filled' => $has->isNotEmpty(),
            };
        }

        $actual = (string) match (true) {
            $row['field'] === 'priority' => $task->priority,
            $row['field'] === 'status' => $task->status,
            $row['field'] === 'assignee' => $task->assignee_id,
            $row['field'] === 'title' => $task->title,
            default => $task->fieldValues()->where('task_field_id', (int) substr($row['field'], 1))->value('value'),
        };

        return match ($row['op']) {
            'is' => mb_strtolower($actual) === mb_strtolower($row['value']),
            'is_not' => mb_strtolower($actual) !== mb_strtolower($row['value']),
            'contains' => str_contains(mb_strtolower($actual), mb_strtolower($row['value'])),
            'empty' => $actual === '',
            'filled' => $actual !== '',
        };
    }

    /** All the actions or none of them, as the person who wrote the rule. */
    public function perform(TaskRuleRun $run): void
    {
        $rule = $run->rule;
        $task = Task::find($run->task_id);

        if (! $task || ! $task->taskList) {
            $run->update(['status' => 'failed', 'error' => 'The task is no longer there.']);

            return;
        }

        $author = $rule->author;
        if (! $author || ! $author->belongsToTeam($task->taskList->team)) {
            $this->failed($run, 'Whoever wrote this rule is no longer on the team.');

            return;
        }

        $before = Auth::user();
        Auth::setUser($author);
        self::$running[] = $rule->id;
        TaskHistory::speakingFor('Rule "'.$rule->name.'"');

        try {
            $done = DB::transaction(fn () => array_map(fn (array $action) => $this->act($action, $task, $author), $rule->actions));
            $run->update(['status' => 'done', 'summary' => implode('. ', $done), 'error' => null, 'attempts' => $run->attempts + 1]);
        } catch (ValidationException|AuthorizationException $refused) {
            // The rule was told no, as a person would be. Trying again would get the same answer.
            $reason = $refused instanceof ValidationException ? $refused->validator->errors()->first() : 'Whoever wrote this rule is not allowed to do this.';
            $this->failed($run, $reason);
        } catch (Throwable $e) {
            $this->tryAgainLater($run, $e);
        } finally {
            TaskHistory::speakingFor(null);
            array_pop(self::$running);
            $before ? Auth::setUser($before) : Auth::forgetUser();
        }
    }

    private function tryAgainLater(TaskRuleRun $run, Throwable $e): void
    {
        Log::error('automation.perform', ['run' => $run->id, 'error' => $e->getMessage()]);
        $attempts = $run->attempts + 1;

        if ($attempts >= self::MOST_ATTEMPTS) {
            $run->attempts = $attempts;
            $this->failed($run, 'It did not work after '.self::MOST_ATTEMPTS.' tries.');

            return;
        }

        $run->update(['status' => 'waiting', 'attempts' => $attempts, 'run_at' => now()->addMinutes(5 * $attempts), 'error' => 'Will try again.']);
    }

    private function failed(TaskRuleRun $run, string $reason): void
    {
        $run->update(['status' => 'failed', 'error' => $reason, 'attempts' => $run->attempts]);

        $author = $run->rule->author;
        $task = Task::find($run->task_id);
        if ($author && $task) {
            $author->notify(new TaskEventNotification($task, 'automation', 'A rule did not run', "\"{$run->rule->name}\" did not run on \"{$task->title}\". {$reason}"));
        }
    }

    /** @param array{type: string, value: string} $action */
    private function act(array $action, Task $task, User $author): string
    {
        $value = $action['value'];

        return match ($action['type']) {
            'assign' => $this->assign($task, $author, $value),
            'priority' => $this->update($task, 'priority', $value),
            'due' => $this->update($task, 'due_date', Today::date()->addDays((int) $value)->toDateString()),
            'move' => $this->move($task, $value),
            'label' => $this->label($task, (int) $value),
            'comment' => $this->comment($task, $author, $value),
            'notify' => $this->notify($task, $value, $author),
            'approval' => $this->approval($task, $author, $value),
        };
    }

    private function may(string $ability, Task $task): void
    {
        if (! Gate::allows($ability, $task)) {
            throw new AuthorizationException;
        }
    }

    private function person(Task $task, string $id): User
    {
        return $task->taskList->team->allUsers()->firstWhere('id', (int) $id)
            ?? throw ValidationException::withMessages(['rule' => 'The person this rule names is no longer on the team.']);
    }

    private function assign(Task $task, User $author, string $id): string
    {
        $this->may('assign', $task);
        $person = $this->person($task, $id);
        app(TaskEditor::class)->assign($task, $person, $author);

        return 'Gave it to '.$person->name;
    }

    private function update(Task $task, string $field, string $value): string
    {
        $this->may('update', $task);
        app(TaskEditor::class)->set($task, $field, $value);

        return 'Set '.str_replace('_', ' ', $field).' to '.$value;
    }

    private function move(Task $task, string $where): string
    {
        $this->may('move', $task);
        $columns = $task->taskList->columns()->get();

        if ($where === 'next') {
            $at = $columns->search(fn (TaskStatus $column) => $column->id === $task->status_id);
            $column = $at === false ? null : $columns->get($at + 1);
            if (! $column) {
                return 'Already in the last column';
            }
        } else {
            $column = $columns->firstWhere('category', $where)
                ?? throw ValidationException::withMessages(['rule' => 'This board has no column for that.']);
        }

        app(TaskPlacement::class)->place($task, $column);

        return 'Moved it to '.$column->name;
    }

    private function label(Task $task, int $id): string
    {
        $this->may('label', $task);
        $task->labels()->syncWithoutDetaching([$id]);

        return 'Added a label';
    }

    private function comment(Task $task, User $author, string $text): string
    {
        $this->may('comment', $task);
        app(TaskCommentService::class)->add($task, $author, $text);

        return 'Added a comment';
    }

    private function approval(Task $task, User $author, string $id): string
    {
        $this->may('update', $task);
        $person = $this->person($task, $id);
        app(TaskApprovals::class)->request($task, $person, $author);

        return 'Asked '.$person->name.' to approve it';
    }

    private function notify(Task $task, string $who, User $author): string
    {
        $id = match ($who) {
            'assignee' => $task->assignee_id,
            'creator' => $task->creator_id,
            default => (int) $who,
        };

        $person = $id ? $task->taskList->team->allUsers()->firstWhere('id', $id) : null;
        if (! $person) {
            return 'Nobody to notify';
        }

        $person->notify(new TaskEventNotification($task, 'automation', 'From a rule', '"'.$task->title.'" needs a look.'));

        return 'Notified '.$person->name;
    }

    /** What is ready now: delayed runs that have come due, and tasks that went overdue. */
    public function sweep(): int
    {
        $ready = TaskRuleRun::where('status', 'waiting')->where('run_at', '<=', now())->with('rule.author')->limit(200)->get();
        $ready->each(fn (TaskRuleRun $run) => $this->perform($run));

        return $ready->count() + $this->sweepOverdue();
    }

    private function sweepOverdue(): int
    {
        $fired = 0;

        foreach (TaskRule::where('trigger', 'overdue')->where('enabled', true)->with('team')->get() as $rule) {
            Today::use($rule->team?->timezone);

            $tasks = Task::forTeamUnscoped($rule->team_id)
                ->when($rule->task_list_id, fn ($q) => $q->where('tasks.task_list_id', $rule->task_list_id))
                ->whereNotNull('tasks.due_date')
                ->whereDate('tasks.due_date', '<', Today::date())
                ->where('tasks.status', '!=', 'done')
                ->limit(500)->get();

            foreach ($tasks as $task) {
                $key = 'overdue:'.$task->id.':'.$task->due_date->toDateString();
                if ($this->fits($rule, $task) && $run = $this->open($rule, $task, $key)) {
                    $fired++;
                    if ($run->status === 'waiting' && $run->run_at->lte(now())) {
                        $this->perform($run);
                    }
                }
            }
        }

        Today::use(null);

        return $fired;
    }

    /** Tasks a rule would take today, so a person can see what it will do before turning it on. @return Collection<int, Task> */
    public function wouldTake(TaskRule $rule): Collection
    {
        return Task::forTeamUnscoped($rule->team_id)
            ->when($rule->task_list_id, fn ($q) => $q->where('tasks.task_list_id', $rule->task_list_id))
            ->where('tasks.status', '!=', 'done')
            ->orderByDesc('tasks.id')->limit(300)->get()
            ->filter(fn (Task $task) => $this->fits($rule, $task))->values();
    }

    /** Past its delay or not, a run an admin approves goes now. */
    public function approve(TaskRuleRun $run, User $by): void
    {
        abort_unless($run->status === 'needs_approval', 409);

        $run->update(['status' => 'waiting', 'decided_by' => $by->id]);

        if ($run->run_at->lte(now())) {
            $this->perform($run);
        }
    }

    public function decline(TaskRuleRun $run, User $by): void
    {
        abort_unless($run->status === 'needs_approval', 409);

        $run->update(['status' => 'declined', 'decided_by' => $by->id]);
    }

    public function retry(TaskRuleRun $run): void
    {
        abort_unless($run->status === 'failed', 409);

        $run->update(['status' => 'waiting', 'attempts' => 0, 'run_at' => now()]);
        $this->perform($run);
    }
}
