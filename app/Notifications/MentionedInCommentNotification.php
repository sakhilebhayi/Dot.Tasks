<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification for a team member @mentioned in a
 * comment. Sent by App\Services\TaskCommentService, at most once per comment
 * per person, and only to members of the task's team.
 */
class MentionedInCommentNotification extends Notification
{
    public function __construct(public Task $task, public TaskComment $comment) {}

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
        $author = $this->comment->user?->name ?? 'Someone';

        return [
            'type' => 'mention',
            'title' => 'You were mentioned',
            'message' => "{$author} mentioned you on \"{$this->task->title}\" on \"{$taskList->name}\".",
            'task_list_id' => $taskList->id,
            'task_id' => $this->task->id,
            'comment_id' => $this->comment->id,
            'url' => route('task-lists.show', $taskList),
        ];
    }
}
