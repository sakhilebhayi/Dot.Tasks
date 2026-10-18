<?php

use App\Broadcasting\TaskListChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| A task list's board carries live task-move updates to everyone with it
| open -- authorization mirrors TaskListPolicy::view() (team membership on
| the list). See TaskListChannelAuthorizer for the actual check and why
| it's factored out into its own class.
|
| $taskListId is deliberately not int-typed here: a malformed value (e.g.
| subscribing to "private-task-list.not-a-number") would otherwise throw
| an uncaught TypeError while PHP tries to coerce it to TaskListChannelAuthorizer::
| authorize()'s int parameter -- an unauthorized 500 instead of a clean,
| fail-closed 403. See docs/DOT_REALTIME_STANDARD.md (Dot.Mines) §6.
*/
Broadcast::channel('task-list.{taskListId}', function ($user, $taskListId) {
    if (! is_string($taskListId) || ! ctype_digit($taskListId)) {
        return false;
    }

    return TaskListChannelAuthorizer::authorize($user, (int) $taskListId);
});
