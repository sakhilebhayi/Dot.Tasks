<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place a task's status changes, whichever screen asked: the board,
 * the drawer, Today/Upcoming or My tasks. Callers authorize first.
 */
class TaskStatusService
{
    public function __construct(
        private readonly TaskRecurrenceService $recurrence,
        private readonly TaskDependencyService $dependencies,
        private readonly TaskHistory $history,
    ) {}

    /**
     * A blocked task cannot be done while any blocker is not: refused here,
     * on the server, whatever the browser sent. Moving it anywhere else is fine.
     *
     * @param  string|TaskStatus  $to  a category, or the column on this task's board
     *
     * @throws ValidationException (key 'status') naming the blockers still open
     */
    public function move(Task $task, string|TaskStatus $to): void
    {
        $column = $to instanceof TaskStatus ? $to : null;
        $status = $column?->category ?? $to;

        if ($status === 'done' && $task->status !== 'done') {
            $open = $this->dependencies->openBlockers($task);

            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'Blocked by '.$open->pluck('title')->map(fn ($t) => "\"{$t}\"")->join(', ').'. Finish those before marking this done.',
                ]);
            }
        }

        // Together, so a task cannot end up done without its next occurrence.
        DB::transaction(function () use ($task, $status, $column) {
            $was = $task->status;
            $wasIn = $task->status_id;
            $task->update(['status' => $status] + ($column ? ['status_id' => $column->id] : []) + match (true) {
                $status === 'done' && $was !== 'done' => ['completed_at' => now(), 'completed_by' => auth()->id()],
                $status !== 'done' && $was === 'done' => ['completed_at' => null, 'completed_by' => null, 'completion_note' => null],
                default => [],
            });

            if ($was !== $status || $wasIn !== $task->status_id) {
                $this->history->record($task, 'status', match (true) {
                    $was !== 'done' && $status === 'done' => 'Finished it',
                    $was === 'done' && $status !== 'done' => 'Reopened it',
                    default => 'Moved it to '.($column?->name ?? TaskStatus::find($task->status_id)?->name ?? $status),
                });
            }

            if ($status === 'done') {
                $this->recurrence->spawnNextOccurrence($task);
            }
        });
    }
}
