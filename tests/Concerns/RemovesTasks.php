<?php

namespace Tests\Concerns;

use App\Models\Task;
use App\Services\TaskArchiveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Helpers for tests that delete tasks the way the app does, and read the removed state back. */
trait RemovesTasks
{
    /** Delete a task (and its subtasks) through the same service the board and drawer use. */
    protected function remove(Task $task, ?string $at = null): Task
    {
        if ($at !== null) {
            Carbon::setTestNow($at);
        }

        app(TaskArchiveService::class)->delete($task, true);

        if ($at !== null) {
            Carbon::setTestNow();
        }

        return $task;
    }

    protected function subtask(object $f, Task $parent, string $title): Task
    {
        return $f->list->tasks()->create(['title' => $title, 'parent_id' => $parent->id, 'status' => 'todo']);
    }

    protected function isRemoved(Task $task): bool
    {
        return Task::withoutGlobalScopes()->whereKey($task->id)->whereNotNull('deleted_at')->exists();
    }

    protected function exists(Task $task): bool
    {
        return Task::withoutGlobalScopes()->whereKey($task->id)->exists();
    }

    /** Every task-related row, to prove a refused or foreign request changed nothing. @return array<string, list<array<string, mixed>>> */
    protected function fingerprint(): array
    {
        $out = [];
        foreach (['tasks', 'task_lists', 'task_comments', 'task_dependencies', 'task_label', 'record_restorations'] as $table) {
            $out[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all();
        }

        return $out;
    }
}
