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
        private readonly TaskNotifier $notifier,
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

        if ($status === 'done' && $task->status !== 'done') {
            $waiting = $task->approvals()->where('status', 'pending')->with('approver')->first();

            if ($waiting) {
                throw ValidationException::withMessages(['status' => 'Waiting for approval from '.$waiting->approver->name.'.']);
            }
        }

        if ($task->status === 'todo' && in_array($status, ['in_progress', 'review'], true)) {
            $open = $this->dependencies->blockersOfStart($task);

            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'This cannot start yet: '.$open->map(fn (Task $blocker) => '"'.$blocker->title.'" '.TaskDependencyService::TYPES[$blocker->pivot->type])->join(', ').'.',
                ]);
            }
        }

        // Together, so a task cannot end up done without its next occurrence.
        $was = $task->status;
        $wasIn = $task->status_id;

        $occurrence = DB::transaction(function () use ($task, $status, $column, $was, $wasIn) {
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
                $next = $this->recurrence->spawnNextOccurrence($task);
            }

            return $next ?? null;
        });

        $this->tellPeople($task, $was, $wasIn, $occurrence);
    }

    /** After the transaction, so nobody hears about a move that did not happen. */
    private function tellPeople(Task $task, string $was, ?int $wasIn, ?Task $occurrence): void
    {
        if ($occurrence) {
            $this->notifier->recurred($occurrence);
        }

        if ($was === $task->status && $wasIn === $task->status_id) {
            return;
        }

        if ($task->status !== 'done') {
            $this->notifier->moved($task, TaskStatus::find($task->status_id)?->name ?? $task->status);
        } else {
            $this->notifier->completed($task);
        }

        $rules = app(Automations::class);
        $stamp = now()->timestamp;
        $rules->fire($task->status === 'done' ? 'completed' : 'moved', $task, "status:{$task->id}:{$task->status_id}:{$stamp}");

        $parent = $task->parent_id ? Task::find($task->parent_id) : null;
        if ($parent && $task->status === 'done' && ! $parent->subtasks()->where('status', '!=', 'done')->exists()) {
            $rules->fire('subtasks_done', $parent, "subtasks:{$parent->id}:{$parent->subtasks()->count()}");
        }

        // Finishing, or starting, can free up what waits on this task.
        foreach ($task->blocking()->with('blockedBy:id,status')->get() as $waiting) {
            if ($waiting->status !== 'done' && ! $waiting->isBlocked()) {
                $this->notifier->unblocked($waiting);
                $rules->fire('unblocked', $waiting, "unblocked:{$waiting->id}:{$stamp}");
            }
        }
    }
}
