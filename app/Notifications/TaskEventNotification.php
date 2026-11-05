<?php

namespace App\Notifications;

use App\Models\Task;
use App\Services\NotificationPreferences;
use Illuminate\Notifications\Notification;

/** Something happened to a task this person is part of. */
class TaskEventNotification extends Notification
{
    public function __construct(public Task $task, public string $event, public string $title, public string $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferences::class)->wants($notifiable, $this->event) ? ['database'] : [];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'task_'.$this->event,
            'title' => $this->title,
            'message' => $this->message,
            'task_list_id' => $this->task->task_list_id,
            'task_id' => $this->task->id,
            'url' => route('task-lists.show', $this->task->task_list_id),
        ];
    }
}
