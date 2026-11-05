<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place a task's status changes, whichever screen asked: the board,
 * the drawer, Today/Upcoming or My tasks. Callers authorize first.
 */
class TaskStatusService
{
    private const NAMES = ['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'];

    public function __construct(
        private readonly TaskRecurrenceService $recurrence,
        private readonly TaskDependencyService $dependencies,
        private readonly TaskHistory $history,
    ) {}

    /**
     * A blocked task cannot be done while any blocker is not: refused here,
     * on the server, whatever the browser sent. Moving it anywhere else is fine.
     *
     * @throws ValidationException (key 'status') naming the blockers still open
     */
    public function move(Task $task, string $status): void
    {
        if ($status === 'done' && $task->status !== 'done') {
            $open = $this->dependencies->openBlockers($task);

            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'Blocked by '.$open->pluck('title')->map(fn ($t) => "\"{$t}\"")->join(', ').'. Finish those before marking this done.',
                ]);
            }
        }

        // Together, so a task cannot end up done without its next occurrence.
        DB::transaction(function () use ($task, $status) {
            $was = $task->status;
            $task->update(['status' => $status] + match (true) {
                $status === 'done' && $was !== 'done' => ['completed_at' => now(), 'completed_by' => auth()->id()],
                $status !== 'done' && $was === 'done' => ['completed_at' => null, 'completed_by' => null, 'completion_note' => null],
                default => [],
            });

            if ($was !== $status) {
                $this->history->record($task, 'status', match (true) {
                    $status === 'done' => 'Finished it',
                    $was === 'done' => 'Reopened it',
                    default => 'Moved it to '.(self::NAMES[$status] ?? $status),
                });
            }

            if ($status === 'done') {
                $this->recurrence->spawnNextOccurrence($task);
            }
        });
    }
}
