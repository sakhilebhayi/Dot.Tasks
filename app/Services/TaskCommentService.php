<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\NewCommentNotification;
use App\Services\Notify\NotifyClient;

/**
 * Adds a comment and tells the right people, each at most once:
 *
 *  - anyone @mentioned in it gets a mention notification;
 *  - the task's assignee and its creator get a new-comment notification
 *    (unless they were mentioned, in which case the mention already told them);
 *  - never the author, and never anyone who is not a member of the task's team.
 *
 * The caller has already authorized the author to comment.
 */
class TaskCommentService
{
    public function add(Task $task, User $author, string $body): TaskComment
    {
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        $team = $task->taskList->team;

        $mentioned = CommentMentions::forTeam($team)->mentioned($body);

        $involved = User::query()
            ->whereIn('id', array_filter([$task->assignee_id, $task->creator_id]))
            ->get();

        $isMember = fn (User $user) => $user->id !== $author->id && $user->belongsToTeam($team);

        foreach ($mentioned->filter($isMember) as $user) {
            $user->notify(new MentionedInCommentNotification($task, $comment));
            app(NotifyClient::class)->taskMentioned($task, $comment, $user, $author);
        }

        $mentionedIds = $mentioned->pluck('id');

        foreach ($involved->filter($isMember)->reject(fn (User $user) => $mentionedIds->contains($user->id)) as $user) {
            $user->notify(new NewCommentNotification($task, $comment));
        }

        return $comment;
    }
}
