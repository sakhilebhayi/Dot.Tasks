<?php

namespace App\Services;

use Illuminate\Support\Str;

/** What a restore brought back and changed, in words for the notice and in numbers for the audit row. */
final readonly class TaskRestoreResult
{
    public function __construct(
        public string $title,
        public bool $isSubtask,
        public int $subtasks = 0,
        public int $assigneesCleared = 0,
        public int $waiting = 0,
        public bool $stillArchived = false,
    ) {}

    public function message(): string
    {
        $message = 'Restored '.($this->isSubtask ? 'subtask' : 'task').' "'.$this->title.'"';

        if ($this->subtasks > 0) {
            $message .= ' with '.$this->subtasks.' '.Str::plural('subtask', $this->subtasks);
        }

        $message .= '.';

        if ($this->waiting > 0) {
            $message .= $this->waiting === 1
                ? ' 1 task is waiting on it again.'
                : " {$this->waiting} tasks are waiting on it again.";
        }

        if ($this->assigneesCleared > 0) {
            $message .= $this->assigneesCleared === 1
                ? ' Its assignee is no longer on the team, so it is unassigned.'
                : " {$this->assigneesCleared} of them had an assignee who is no longer on the team, so they are unassigned.";
        }

        if ($this->stillArchived) {
            $message .= " It is still archived: restore it from its board's Archived list.";
        }

        return $message;
    }
}
