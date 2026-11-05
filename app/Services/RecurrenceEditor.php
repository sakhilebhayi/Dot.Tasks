<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The rule a series follows. A rule belongs to the series, so a change reaches the task it started from and every
 * occurrence still open. A task's own details stay its own until someone says to use them for the series. Callers
 * authorize first.
 */
class RecurrenceEditor
{
    public const KINDS = ['daily' => 'Days', 'weekly' => 'Weeks', 'monthly' => 'Months', 'yearly' => 'Years'];

    /** @param  array{kind: string, every: mixed, days?: array<int, mixed>, basis: string, until?: ?string, limit?: mixed, missed: string}  $rule */
    public function set(Task $task, array $rule): void
    {
        $valid = Validator::make($rule, [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'every' => ['required', 'integer', 'min:1', 'max:365'],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'basis' => ['required', Rule::in(['due_date', 'completion'])],
            'until' => ['nullable', 'date', $task->due_date ? 'after_or_equal:'.$task->due_date->toDateString() : 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'missed' => ['required', Rule::in(['skip', 'keep'])],
        ], [], ['until' => 'end date', 'limit' => 'number of times', 'every' => 'interval'])->validate();

        $yearly = $valid['kind'] === 'yearly';
        $days = $valid['kind'] === 'weekly' ? array_values(array_unique(array_map('intval', $valid['days'] ?? []))) : [];
        sort($days);

        $this->everyOpen($task)->each->update([
            'recurrence_type' => $yearly ? 'monthly' : $valid['kind'],
            'recurrence_interval' => $yearly ? (int) $valid['every'] * 12 : (int) $valid['every'],
            'recurrence_days' => $days === [] ? null : $days,
            'recurrence_anchor' => $valid['basis'],
            'recurrence_until' => ($valid['until'] ?? null) ?: null,
            'recurrence_limit' => ($valid['limit'] ?? null) ?: null,
            'recurrence_missed' => $valid['missed'],
        ]);
    }

    /** No more occurrences after this one. */
    public function stop(Task $task): void
    {
        $this->everyOpen($task)->each->update(['recurrence_type' => null]);
    }

    /** What this occurrence says becomes what the next ones start from. */
    public function useForSeries(Task $task): void
    {
        $root = $this->root($task);
        if ($root->is($task)) {
            return;
        }

        DB::transaction(function () use ($task, $root) {
            $root->update($task->only(['title', 'description', 'priority', 'assignee_id', 'estimated_minutes']));
            $root->labels()->sync($task->labels->pluck('id'));
        });
    }

    /** The task a series began with, which is what each new occurrence copies. */
    public function root(Task $task): Task
    {
        $id = $task->recurrence_parent_id ?? $task->id;

        return Task::withTrashed()->withArchived()->find($id) ?? $task;
    }

    private function everyOpen(Task $task)
    {
        $root = $this->root($task);

        return Task::withTrashed()->withArchived()->with('taskList')
            ->where(fn ($q) => $q->whereKey($root->id)->orWhere('recurrence_parent_id', $root->id))
            ->where(fn ($q) => $q->whereKey($root->id)->orWhere('status', '!=', 'done'))
            ->get();
    }
}
