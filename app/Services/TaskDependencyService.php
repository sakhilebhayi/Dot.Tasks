<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Blocked by" links between tasks of one board. Rules, all enforced when the
 * link is written: both tasks are on the same board, a task is not its own
 * blocker, a pair is linked once, and a link may never close a loop (A blocked
 * by B blocked by A, directly or through other tasks), since nothing in a loop
 * could ever be finished.
 */
class TaskDependencyService
{
    /** What each kind of link asks of the other task, said from this task's side. */
    public const TYPES = [
        'ff' => 'has to finish before this finishes',
        'fs' => 'has to finish before this starts',
        'ss' => 'has to start before this starts',
        'sf' => 'has to start before this finishes',
    ];

    /**
     * @throws ValidationException (key 'blocker')
     */
    public function add(Task $task, int $blockerId, string $type = 'ff'): Task
    {
        if (! array_key_exists($type, self::TYPES)) {
            $this->refuse('Pick how the tasks depend on each other.');
        }

        // Looked up inside the task's own board, so an id from another board,
        // another team, or that does not exist all get the same answer.
        $blocker = Task::withArchived()
            ->where('task_list_id', $task->task_list_id)
            ->find($blockerId);

        if (! $blocker) {
            $this->refuse('Pick a task from this board.');
        }

        if ($blocker->id === $task->id) {
            $this->refuse('A task cannot be blocked by itself.');
        }

        if ($task->blockedBy()->whereKey($blocker->id)->exists()) {
            $this->refuse("This task is already blocked by \"{$blocker->title}\".");
        }

        if ($this->reaches($blocker->id, $task->id)) {
            $this->refuse("\"{$blocker->title}\" is already blocked by this task, directly or through other tasks, so this would make a loop that nothing could finish.");
        }

        DB::table('task_dependencies')->insert(['task_id' => $task->id, 'blocked_by_id' => $blocker->id, 'type' => $type]);

        if ($task->fresh('blockedBy')->isBlocked()) {
            app(Automations::class)->fire('blocked', $task, "blocked:{$task->id}:{$blocker->id}");
        }

        return $blocker;
    }

    public function remove(Task $task, int $blockerId): void
    {
        DB::table('task_dependencies')
            ->where('task_id', $task->id)
            ->where('blocked_by_id', $blockerId)
            ->delete();
    }

    /**
     * The blockers still standing in the way of finishing: not deleted, and not yet where their link asks. An archived
     * blocker still counts, until it gets there or the link is removed.
     *
     * @return Collection<int, Task>
     */
    public function openBlockers(Task $task): Collection
    {
        return $task->blockedBy()->get()->filter(fn (Task $blocker) => in_array($blocker->pivot->type, ['ff', 'fs'], true)
            ? $blocker->status !== 'done'
            : $blocker->status === 'todo')->values();
    }

    /**
     * What stands in the way of starting. Only links about the start count here, and work that has already started has
     * nothing to wait for.
     *
     * @return Collection<int, Task>
     */
    public function blockersOfStart(Task $task): Collection
    {
        return $task->blockedBy()->wherePivotIn('type', ['fs', 'ss'])->get()->filter(fn (Task $blocker) => $blocker->pivot->type === 'fs'
            ? $blocker->status !== 'done'
            : $blocker->status === 'todo')->values();
    }

    /** Does following "blocked by" links from $fromId ever reach $targetId? */
    private function reaches(int $fromId, int $targetId): bool
    {
        $seen = [$fromId];
        $frontier = [$fromId];

        while ($frontier !== []) {
            $next = DB::table('task_dependencies')
                ->whereIn('task_id', $frontier)
                ->pluck('blocked_by_id')
                ->map(fn ($id) => (int) $id)
                ->diff($seen)
                ->values()
                ->all();

            if (in_array($targetId, $next, true)) {
                return true;
            }

            $seen = [...$seen, ...$next];
            $frontier = $next;
        }

        return false;
    }

    /** @return never */
    private function refuse(string $message): void
    {
        throw ValidationException::withMessages(['blocker' => $message]);
    }
}
