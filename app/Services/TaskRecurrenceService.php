<?php

namespace App\Services;

use App\Models\Task;
use Carbon\Carbon;

/**
 * Spawns the next occurrence of a recurring task once the current one is
 * marked "done" -- see TaskBoard::moveTask(). A new Task row is created for
 * each occurrence (linked via recurrence_parent_id) rather than mutating
 * the completed one in place, so completed occurrences stay in history
 * instead of disappearing/resetting.
 */
class TaskRecurrenceService
{
    /**
     * Create the next occurrence of $completedTask, or return null if the
     * task isn't recurring.
     */
    public function spawnNextOccurrence(Task $completedTask): ?Task
    {
        if (! $completedTask->isRecurring()) {
            return null;
        }

        // A series stops when its source is archived or deleted, or sits on an
        // archived board: completing it then must not quietly make new work.
        if ($completedTask->trashed() || $completedTask->isArchived() || $completedTask->taskList === null) {
            return null;
        }

        $anchorDate = $completedTask->recurrence_anchor === 'completion'
            ? Carbon::today()
            : ($completedTask->due_date ?? Carbon::today());

        $nextDueDate = $this->nextDate(
            $anchorDate,
            $completedTask->recurrence_type,
            $completedTask->recurrence_interval ?? 1
        );

        $seriesParentId = $completedTask->recurrence_parent_id ?? $completedTask->id;

        $occurrence = Task::create([
            'task_list_id' => $completedTask->task_list_id,
            'assignee_id' => $completedTask->assignee_id,
            'creator_id' => $completedTask->creator_id,
            'title' => $completedTask->title,
            'description' => $completedTask->description,
            'status' => 'todo',
            // Falls back rather than trusting the in-memory attribute blindly:
            // a Task created via ::create() without an explicit 'priority'
            // gets the column's DB-level default applied by SQL, but the
            // in-memory model isn't refreshed to reflect that -- passing a
            // null straight through here would violate the NOT NULL
            // constraint on this new row instead of quietly inheriting it.
            'priority' => $completedTask->priority ?? 'medium',
            'due_date' => $nextDueDate,
            'estimated_minutes' => $completedTask->estimated_minutes,
            'sort_order' => $completedTask->sort_order ?? 0,
            'recurrence_type' => $completedTask->recurrence_type,
            'recurrence_interval' => $completedTask->recurrence_interval ?? 1,
            'recurrence_anchor' => $completedTask->recurrence_anchor ?? 'due_date',
            'recurrence_parent_id' => $seriesParentId,
        ]);

        $occurrence->labels()->sync($completedTask->labels->pluck('id'));

        return $occurrence;
    }

    private function nextDate(Carbon $from, string $type, int $interval): Carbon
    {
        $date = $from->copy();

        return match ($type) {
            'daily', 'custom_days' => $date->addDays($interval),
            'weekly' => $date->addWeeks($interval),
            'monthly' => $date->addMonths($interval),
            default => $date->addDay(),
        };
    }
}
