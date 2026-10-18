<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a task's assignee and its
 * creator when someone else comments on it. Sent by
 * App\Services\TaskCommentService, once per person per comment.
 */
class NewCommentNotification extends Notification
{
    public function __construct(public Task $task, public ?TaskComment $comment = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $taskList = $this->task->taskList;
        $author = $this->comment?->user?->name ?? 'Someone';

        return [
            'type' => 'new_comment',
            'title' => 'New comment on your task',
            'message' => "{$author} commented on \"{$this->task->title}\" on \"{$taskList->name}\".",
            'task_list_id' => $taskList->id,
            'task_id' => $this->task->id,
            'comment_id' => $this->comment?->id,
            'url' => route('task-lists.show', $taskList),
        ];
    }
}
