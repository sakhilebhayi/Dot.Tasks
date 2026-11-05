<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use Illuminate\Support\Collection;

/** Tells the people on a task what happened to it, each once, and never the person who did it. Inboxes are private and stay quiet. */
class TaskNotifier
{
    public function moved(Task $task, string $column): void
    {
        $this->tell($task, 'status', $this->around($task), 'Task moved', "\"{$task->title}\" moved to {$column}.");
    }

    public function completed(Task $task): void
    {
        $this->tell($task, 'completed', $this->around($task)->push($task->creator_id), 'Task finished', "\"{$task->title}\" is finished.");
    }

    public function dueChanged(Task $task): void
    {
        $when = $task->due_date?->format('j M Y');

        $this->tell($task, 'due_changed', $this->around($task), 'Due date changed', $when ? "\"{$task->title}\" is now due {$when}." : "\"{$task->title}\" no longer has a due date.");
    }

    public function unblocked(Task $task): void
    {
        $this->tell($task, 'unblocked', collect([$task->assignee_id]), 'Nothing holding it up', "\"{$task->title}\" is no longer held up.");
    }

    public function recurred(Task $task): void
    {
        $when = $task->due_date?->format('j M Y');

        $this->tell($task, 'recurring', collect([$task->assignee_id]), 'Repeating task is back', "\"{$task->title}\" has come round again".($when ? ", due {$when}." : '.'));
    }

    /** @return Collection<int, int|null> */
    private function around(Task $task): Collection
    {
        return collect([$task->assignee_id])
            ->merge($task->collaborators()->pluck('users.id'))
            ->merge($task->watchers()->pluck('users.id'));
    }

    /** @param  Collection<int, int|null>  $ids */
    private function tell(Task $task, string $event, Collection $ids, string $title, string $message): void
    {
        $board = $task->taskList;
        if (! $board || $board->is_inbox) {
            return;
        }

        $ids = $ids->filter()->unique()->reject(fn (int $id) => $id === auth()->id());

        User::whereIn('id', $ids)->get()
            ->filter(fn (User $user) => $user->belongsToTeam($board->team))
            ->each(fn (User $user) => $user->notify(new TaskEventNotification($task, $event, $title, $message)));
    }
}
