<?php

namespace App\Services;

use App\Models\Task;

/**
 * The one place a task's status changes, whichever screen asked: the board,
 * the drawer, Today/Upcoming or My tasks. Callers authorize first.
 */
class TaskStatusService
{
    public function __construct(private readonly TaskRecurrenceService $recurrence) {}

    public function move(Task $task, string $status): void
    {
        $task->update(['status' => $status]);

        if ($status === 'done') {
            $this->recurrence->spawnNextOccurrence($task);
        }
    }
}
