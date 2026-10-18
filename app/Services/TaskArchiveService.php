<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Archive, restore and delete tasks, with their subtasks handled explicitly.
 *
 * Archiving or deleting a task that has subtasks takes the subtasks with it,
 * but only once the caller has confirmed that: without it nothing changes and
 * the error says how many subtasks are involved. Archived tasks come back with
 * restore(); deletion is a soft delete and is not offered back in the app.
 */
class TaskArchiveService
{
    /**
     * @throws ValidationException (key 'archive') when there are subtasks and $confirmed is false
     */
    public function archive(Task $task, bool $confirmed = false): void
    {
        $subtasks = $this->descendantIds($task, includeArchived: false);

        if ($subtasks !== [] && ! $confirmed) {
            throw ValidationException::withMessages([
                'archive' => 'Archiving this task also archives its '.$this->subtasks(count($subtasks)).'. Confirm to continue.',
            ]);
        }

        // One timestamp for the whole cascade so restore() can tell which
        // subtasks went with the parent and which were archived on their own.
        $at = now();

        DB::transaction(function () use ($task, $subtasks, $at) {
            Task::withArchived()->whereIn('id', [$task->id, ...$subtasks])->update(['archived_at' => $at]);
        });

        $task->archived_at = $at;
    }

    /**
     * Bring a task back, together with the subtasks that were archived with it.
     *
     * @throws ValidationException (key 'restore') when its parent is still archived
     */
    public function restore(Task $task): void
    {
        if ($task->parent_id !== null && Task::onlyArchived()->whereKey($task->parent_id)->exists()) {
            throw ValidationException::withMessages([
                'restore' => 'Restore the parent task first, then this subtask comes back with it or on its own.',
            ]);
        }

        $stamp = $task->archived_at;
        $withIt = $this->descendantIds($task, includeArchived: true, archivedAt: $stamp);

        DB::transaction(function () use ($task, $withIt) {
            Task::withArchived()->whereIn('id', [$task->id, ...$withIt])->update(['archived_at' => null]);
        });

        $task->archived_at = null;
    }

    /**
     * Soft delete a task and all of its subtasks, archived ones included.
     *
     * @throws ValidationException (key 'delete') when there are subtasks and $confirmed is false
     */
    public function delete(Task $task, bool $confirmed = false): void
    {
        $subtasks = $this->descendantIds($task, includeArchived: true);

        if ($subtasks !== [] && ! $confirmed) {
            throw ValidationException::withMessages([
                'delete' => 'Deleting this task also deletes its '.$this->subtasks(count($subtasks)).'. Confirm to continue.',
            ]);
        }

        DB::transaction(function () use ($task, $subtasks) {
            Task::withArchived()->whereIn('id', [$task->id, ...$subtasks])->delete();
        });
    }

    /** How many subtasks archiving this task would take along (for the confirmation text). */
    public function subtaskCount(Task $task, bool $includeArchived = false): int
    {
        return count($this->descendantIds($task, $includeArchived));
    }

    /**
     * Ids of every task below this one, at any depth, not soft-deleted.
     *
     * @return list<int>
     */
    private function descendantIds(Task $task, bool $includeArchived, ?\DateTimeInterface $archivedAt = null): array
    {
        $found = [];
        $frontier = [$task->id];

        while ($frontier !== []) {
            $children = Task::query()
                ->when($includeArchived, fn ($q) => $q->withArchived())
                ->when($archivedAt, fn ($q) => $q->where('archived_at', $archivedAt))
                ->whereIn('parent_id', $frontier)
                ->pluck('id');

            $frontier = $children->diff($found)->values()->all();
            $found = [...$found, ...$frontier];
        }

        return $found;
    }

    private function subtasks(int $count): string
    {
        return $count === 1 ? '1 subtask' : "{$count} subtasks";
    }
}
