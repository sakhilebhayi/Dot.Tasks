<?php

namespace App\Notifications;

use App\Models\Task;
use App\Services\NotificationPreferences;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a task's assignee when the
 * task is due within the next two days and hasn't been completed yet.
 * Dispatched by the scheduled App\Console\Commands\CheckTasksDueSoon command.
 */
class TaskDueSoonNotification extends Notification
{
    public function __construct(public Task $task) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferences::class)->wants($notifiable, 'due_soon') ? ['database'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $taskList = $this->task->taskList;

        return [
            'type' => 'task_due_soon',
            'title' => 'Task due soon',
            'message' => "\"{$this->task->title}\" on \"{$taskList->name}\" is due ".$this->task->due_date->format('M d, Y').'.',
            'task_list_id' => $taskList->id,
            'task_id' => $this->task->id,
            'url' => route('task-lists.show', $taskList),
        ];
    }
}
