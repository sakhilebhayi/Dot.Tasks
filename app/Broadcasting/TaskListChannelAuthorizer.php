<?php

namespace App\Broadcasting;

use App\Models\TaskList;
use App\Models\User;

/**
 * Authorization for the 'task-list.{taskListId}' private channel, factored
 * out of routes/channels.php so it can be unit tested directly -- the
 * "null" broadcast driver (what this app's tests force via phpunit.xml)
 * never actually invokes a channel's callback via the /broadcasting/auth
 * HTTP endpoint, so that endpoint can't be used to verify this logic.
 *
 * Mirrors TaskListPolicy::view() exactly: a task list belongs to a team, so
 * team membership is the whole authorization boundary. (This platform has
 * no HasTeamScope-style global scope on any model -- see wiki.md §2 -- so
 * a plain find() is enough; there's no scope to bypass.)
 */
class TaskListChannelAuthorizer
{
    public static function authorize(User $user, int $taskListId): bool
    {
        $taskList = TaskList::find($taskListId);

        if (! $taskList) {
            return false;
        }

        return $user->belongsToTeam($taskList->team);
    }
}
