<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The one place a task's own fields change. Callers authorize first. */
class TaskEditor
{
    public const FIELDS = [
        'title' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:20000'],
        'due_date' => ['nullable', 'date'],
        'start_date' => ['nullable', 'date'],
        'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        'completion_criteria' => ['nullable', 'string', 'max:5000'],
    ];

    private const NAMES = [
        'title' => 'title', 'description' => 'description', 'due_date' => 'due date', 'start_date' => 'start date',
        'estimated_minutes' => 'estimate', 'actual_minutes' => 'time spent', 'completion_criteria' => 'what done means', 'priority' => 'priority',
    ];

    public function __construct(private readonly TaskHistory $history) {}

    /**
     * @throws ValidationException (key is the field)
     */
    public function set(Task $task, string $field, mixed $value): void
    {
        $rules = $field === 'priority' ? ['required', Rule::in(Task::PRIORITIES)] : (self::FIELDS[$field] ?? null);
        if ($rules === null) {
            throw ValidationException::withMessages(['task' => 'That cannot be changed here.']);
        }

        $value = is_string($value) ? trim($value) : $value;
        $value = $value === '' ? null : $value;
        Validator::make([$field => $value], [$field => $rules], [], [$field => self::NAMES[$field]])->validate();

        $this->guardDates($task, $field, $value);

        $before = $this->plain($task->{$field});
        if ($before === (string) $value) {
            return;
        }

        $task->update([$field => $value]);
        $this->history->record($task, 'edit', $this->sentence($field, $value));
    }

    /** @param  list<int>  $userIds */
    public function setCollaborators(Task $task, array $userIds): void
    {
        $team = $task->taskList->team;
        $allowed = $team->allUsers()->pluck('id')->all();
        $ids = array_values(array_intersect($allowed, array_map('intval', $userIds)));

        $task->collaborators()->sync($ids);
        $names = User::whereIn('id', $ids)->orderBy('name')->pluck('name')->join(', ');
        $this->history->record($task, 'people', $names === '' ? 'Removed the collaborators' : 'Working with '.$names);
    }

    private function guardDates(Task $task, string $field, mixed $value): void
    {
        $start = $field === 'start_date' ? $value : $task->start_date;
        $due = $field === 'due_date' ? $value : $task->due_date;

        if ($start !== null && $due !== null && Carbon::parse($start)->gt(Carbon::parse($due))) {
            throw ValidationException::withMessages([$field => 'The start date has to be on or before the due date.']);
        }
    }

    private function plain(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    private function sentence(string $field, mixed $value): string
    {
        if ($value === null) {
            return 'Cleared the '.self::NAMES[$field];
        }

        return match ($field) {
            'title' => 'Renamed it to '.$value,
            'description', 'completion_criteria' => 'Changed the '.self::NAMES[$field],
            'due_date', 'start_date' => 'Set the '.self::NAMES[$field].' to '.Carbon::parse($value)->format('M j, Y'),
            'estimated_minutes', 'actual_minutes' => 'Set the '.self::NAMES[$field].' to '.$value.' minutes',
            default => 'Set the '.self::NAMES[$field].' to '.$value,
        };
    }
}
