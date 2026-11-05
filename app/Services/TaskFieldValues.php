<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** What a custom field holds on a task, checked against its type. Callers authorize first. */
class TaskFieldValues
{
    public function __construct(private readonly TaskHistory $history) {}

    public function set(Task $task, TaskField $field, ?string $value): void
    {
        $value = $value === null ? '' : trim($value);
        $row = DB::table('task_field_values')->where('task_id', $task->id)->where('task_field_id', $field->id);

        if ($value === '' || ($field->type === 'checkbox' && $value === '0')) {
            if ($row->delete() > 0) {
                $this->history->record($task, 'edit', 'Cleared '.$field->name);
            }

            return;
        }

        $this->check($field, $value);

        if ($row->value('value') === $value) {
            return;
        }

        DB::table('task_field_values')->updateOrInsert(['task_id' => $task->id, 'task_field_id' => $field->id], ['value' => $value]);
        $this->history->record($task, 'edit', 'Set '.$field->name.' to '.($field->type === 'checkbox' ? 'yes' : $value));
    }

    /** @throws ValidationException (key 'field') */
    private function check(TaskField $field, string $value): void
    {
        $fits = match ($field->type) {
            'number' => is_numeric($value),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && Carbon::hasFormatWithModifiers($value, 'Y#m#d') && Carbon::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value,
            'select' => in_array($value, $field->options ?? [], true),
            'checkbox' => $value === '1',
            default => mb_strlen($value) <= 500,
        };

        if (! $fits) {
            throw ValidationException::withMessages(['field' => $field->name.' does not take that value.']);
        }
    }
}
