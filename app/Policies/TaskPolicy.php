<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamRole;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * A task's authorization boundary is the team that owns its parent
 * TaskList — a task never belongs to a user directly. Without this policy,
 * App\Livewire\Tasks\TaskDetail::openTask() would load any task by ID
 * dispatched from the client (the `open-task` Livewire event), letting an
 * authenticated user from Team A view, comment on, change the status of,
 * or run AI breakdown against a task belonging to Team B.
 *
 * Within a team the role decides: viewers read, editors work (create, update,
 * move, assign, label, comment), admins and the owner also delete.
 */
class TaskPolicy
{
    use ChecksTeamRole;
    use HandlesAuthorization;

    public function view(User $user, Task $task): bool
    {
        // taskList is null for a task on an archived board.
        $team = $task->taskList?->team;

        return $team !== null && $user->belongsToTeam($team);
    }

    /** Add a task to a board: authorized against the board it lands on. */
    public function create(User $user, TaskList $taskList): bool
    {
        return $this->holds($user, $taskList->team, 'create');
    }

    public function update(User $user, Task $task): bool
    {
        return $this->holds($user, $task->taskList?->team, 'update');
    }

    /** Change a task's status, from the board, the drawer or a smart view. */
    public function move(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function label(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /** Archiving is reversible, so editors may do it. */
    public function archive(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Bring a DELETED task back from the Removed page: as strong an act as deleting it, so admins
     * and the owner only. (restore() above is the editor-level "un-archive".) The board is looked up
     * with archived boards included, because a deleted task on an archived board is still the
     * team's to restore once the board is.
     */
    public function restoreDeleted(User $user, Task $task): bool
    {
        $board = TaskList::withArchived()->with('team')->find($task->task_list_id);

        return $this->holds($user, $board?->team, 'delete');
    }

    /** Deleting is for admins and the owner. */
    public function delete(User $user, Task $task): bool
    {
        return $this->holds($user, $task->taskList?->team, 'delete');
    }

    /**
     * Determine whether the user can review an escalation proposal for the
     * task (approve or reject). Narrower than update() -- any editor can edit
     * a task, but only the task list's owner may decide whether an overdue
     * task actually gets escalated, and a read-only viewer never can.
     */
    public function escalate(User $user, Task $task): bool
    {
        $taskList = $task->taskList;

        return $taskList !== null
            && $user->id === $taskList->owner_id
            && $this->holds($user, $taskList->team, 'update');
    }
}
