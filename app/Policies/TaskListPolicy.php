<?php

namespace App\Policies;

use App\Models\TaskList;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamRole;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class TaskListPolicy
{
    use ChecksTeamRole;
    use HandlesAuthorization;

    /** Refused as not found, so ids cannot be walked to learn which boards exist. */
    public function view(User $user, TaskList $taskList): Response
    {
        $allowed = $taskList->is_inbox ? $user->id === $taskList->owner_id : $user->belongsToTeam($taskList->team);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    /** Creating a board puts it on the user's current team, so that is the team checked. */
    public function create(User $user): bool
    {
        return $this->holds($user, $user->currentTeam, 'create');
    }

    /** Work on the board's tasks: move cards between columns. */
    public function update(User $user, TaskList $taskList): bool
    {
        if ($taskList->is_inbox) {
            return $user->id === $taskList->owner_id;
        }

        return $this->holds($user, $taskList->team, 'update');
    }

    /** Archiving a board hides it and everything on it: admins and the owner only. */
    public function archive(User $user, TaskList $taskList): bool
    {
        return ! $taskList->is_inbox && $this->holds($user, $taskList->team, 'delete');
    }

    public function restore(User $user, TaskList $taskList): bool
    {
        return $this->archive($user, $taskList);
    }

    public function delete(User $user, TaskList $taskList): bool
    {
        return $this->archive($user, $taskList);
    }
}
