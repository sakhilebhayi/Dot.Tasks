<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Str;

/** What a task leaves open, read from the task itself. It costs nothing and sends nothing anywhere. */
class TaskClarity
{
    private const VAGUE = ['fix', 'update', 'check', 'stuff', 'things', 'task', 'todo', 'misc', 'follow up', 'sort out', 'look at', 'look into', 'review', 'meeting', 'admin'];

    /** @return list<string> */
    public function gaps(Task $task): array
    {
        $title = Str::lower(trim($task->title));
        $words = str_word_count($title);

        return array_values(array_filter([
            $words < 3 || in_array($title, self::VAGUE, true) ? 'The title is short. Could someone else tell what it means?' : null,
            $task->assignee_id === null ? 'Nobody is on it.' : null,
            $task->due_date === null ? 'It has no due date.' : null,
            blank($task->description) && in_array($task->priority, ['high', 'urgent'], true) ? 'It matters, and has no description.' : null,
            blank($task->completion_criteria) ? 'Nothing says what done means.' : null,
        ]));
    }
}
