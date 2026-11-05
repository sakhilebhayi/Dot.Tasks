<?php

namespace App\Services;

use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
     * task isn't recurring or has already made its next one. A task finished
     * again after being reopened, or dropped on Done twice, makes no more.
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

        if ($this->alreadyMade($completedTask)) {
            return null;
        }

        $count = $completedTask->recurrence_count ?? 1;
        if ($completedTask->recurrence_limit !== null && $count >= $completedTask->recurrence_limit) {
            return null;
        }

        $series = Task::withTrashed()->withArchived()->find($completedTask->recurrence_parent_id ?? $completedTask->id) ?? $completedTask;
        $today = Carbon::today();
        $nextDueDate = $this->nextDate($completedTask->recurrence_anchor === 'completion' ? $today : ($completedTask->due_date ?? $today), $completedTask, $series);

        // A series that fell behind can pick up from today rather than hand over a pile of overdue occurrences.
        for ($turns = 0; $completedTask->recurrence_missed === 'skip' && $nextDueDate->lt($today) && $turns < 2000; $turns++) {
            $nextDueDate = $this->nextDate($nextDueDate, $completedTask, $series);
        }

        if ($completedTask->recurrence_until !== null && $nextDueDate->gt($completedTask->recurrence_until)) {
            return null;
        }

        try {
            // Its own savepoint: Postgres abandons a whole transaction after a refused insert unless it can step back.
            $occurrence = DB::transaction(fn () => $this->makeOccurrence($completedTask, $nextDueDate, $series));
        } catch (UniqueConstraintViolationException) {
            // Someone else finished it at the same moment, and theirs made the occurrence.
            return null;
        }

        $occurrence->labels()->sync($series->labels()->pluck('labels.id'));
        foreach ($series->fieldValues as $value) {
            $occurrence->fieldValues()->create(['task_field_id' => $value->task_field_id, 'value' => $value->value]);
        }

        return $occurrence;
    }

    /** Archived and deleted occurrences count: they were made, and someone chose to set them aside. */
    protected function alreadyMade(Task $task): bool
    {
        return Task::withTrashed()->withArchived()->where('recurrence_source_id', $task->id)->exists();
    }

    /** The rule comes from the occurrence just finished. The details come from the series, so an edit to one occurrence stays with it. */
    protected function makeOccurrence(Task $completedTask, Carbon $nextDueDate, Task $series): Task
    {
        return Task::create([
            'task_list_id' => $completedTask->task_list_id,
            'assignee_id' => $series->assignee_id,
            'creator_id' => $completedTask->creator_id,
            'title' => $series->title,
            'description' => $series->description,
            'status' => 'todo',
            // Falls back rather than trusting the in-memory attribute blindly:
            // a Task created via ::create() without an explicit 'priority'
            // gets the column's DB-level default applied by SQL, but the
            // in-memory model isn't refreshed to reflect that -- passing a
            // null straight through here would violate the NOT NULL
            // constraint on this new row instead of quietly inheriting it.
            'priority' => $series->priority ?? 'medium',
            'due_date' => $nextDueDate,
            'estimated_minutes' => $series->estimated_minutes,
            'sort_order' => $completedTask->sort_order ?? 0,
            'recurrence_type' => $completedTask->recurrence_type,
            'recurrence_interval' => $completedTask->recurrence_interval ?? 1,
            'recurrence_anchor' => $completedTask->recurrence_anchor ?? 'due_date',
            'recurrence_days' => $completedTask->recurrence_days,
            'recurrence_until' => $completedTask->recurrence_until,
            'recurrence_limit' => $completedTask->recurrence_limit,
            'recurrence_count' => ($completedTask->recurrence_count ?? 1) + 1,
            'recurrence_missed' => $completedTask->recurrence_missed ?? 'keep',
            'recurrence_parent_id' => $series->id,
            'recurrence_source_id' => $completedTask->id,
        ]);
    }

    private function nextDate(Carbon $from, Task $rule, Task $series): Carbon
    {
        $date = $from->copy()->startOfDay();
        $interval = $rule->recurrence_interval ?? 1;

        return match ($rule->recurrence_type) {
            'daily', 'custom_days' => $date->addDays($interval),
            'weekly' => $this->nextWeekday($date, $interval, $rule->recurrence_days ?? []),
            'monthly' => $this->nextMonthDay($date, $interval, $series->due_date?->day ?? $date->day),
            default => $date->addDay(),
        };
    }

    /** The next chosen weekday in this week, or the first one in the next week that counts. @param list<int> $days */
    private function nextWeekday(Carbon $date, int $interval, array $days): Carbon
    {
        if ($days === []) {
            return $date->addWeeks($interval);
        }

        sort($days);
        $monday = $date->copy()->startOfWeek(Carbon::MONDAY);

        foreach ($days as $day) {
            if ($day > $date->dayOfWeekIso) {
                return $monday->addDays($day - 1);
            }
        }

        return $monday->addWeeks($interval)->addDays($days[0] - 1);
    }

    /** The same day of the month each time. A short month takes its last day, and the 31st comes back the month after. */
    private function nextMonthDay(Carbon $date, int $interval, int $day): Carbon
    {
        $month = $date->copy()->startOfMonth()->addMonths($interval);

        return $month->day(min($day, $month->daysInMonth));
    }
}
