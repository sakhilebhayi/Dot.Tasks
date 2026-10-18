<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * A task's authorization boundary is the team that owns its parent
 * TaskList — a task never belongs to a user directly. Without this policy,
 * App\Livewire\Tasks\TaskDetail::openTask() would load any task by ID
 * dispatched from the client (the `open-task` Livewire event), letting an
 * authenticated user from Team A view, comment on, change the status of,
 * or run AI breakdown against a task belonging to Team B.
 */
class TaskPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Task $task): bool
    {
        return $user->belongsToTeam($task->taskList->team);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->belongsToTeam($task->taskList->team);
    }

    /**
     * Determine whether the user can review an escalation proposal for the
     * task (approve or reject). Narrower than update() -- any team member
     * can edit a task, but only the task list's owner may decide whether an
     * overdue task actually gets escalated.
     */
    public function escalate(User $user, Task $task): bool
    {
        $taskList = $task->taskList;

        return $user->id === $taskList->owner_id
            && $user->belongsToTeam($taskList->team);
    }
}
