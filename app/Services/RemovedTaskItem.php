<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskList;

/**
 * One row of the Removed page: a deleted task with its board and parent, and
 * the reason it cannot be restored yet (if any).
 *
 * `blockedBy` is ['kind' => 'parent_removed'|'parent_archived'|'board_archived'|'board_missing', 'name' => string]
 * or null when the task can be restored right now.
 */
final readonly class RemovedTaskItem
{
    /** @param array{kind: string, name: string}|null $blockedBy */
    public function __construct(
        public Task $task,
        public ?TaskList $board,
        public ?Task $parent,
        public ?array $blockedBy,
    ) {}

    public function isSubtask(): bool
    {
        return $this->task->parent_id !== null;
    }
}
