<?php

namespace App\Notifications;

use App\Models\Task;
use App\Services\NotificationPreferences;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a user the moment they are
 * assigned a task on a board. Dispatched from
 * App\Livewire\Tasks\TaskDetail::assignTask().
 */
class TaskAssignedNotification extends Notification
{
    public function __construct(public Task $task) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferences::class)->wants($notifiable, 'assigned') ? ['database'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $taskList = $this->task->taskList;

        return [
            'type' => 'task_assigned',
            'title' => 'Task assigned to you',
            'message' => "You were assigned \"{$this->task->title}\" on \"{$taskList->name}\".",
            'task_list_id' => $taskList->id,
            'task_id' => $this->task->id,
            'url' => route('task-lists.show', $taskList),
        ];
    }
}
