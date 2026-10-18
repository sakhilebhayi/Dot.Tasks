<?php

namespace App\Policies\Concerns;

use App\Models\Team;
use App\Models\User;

/**
 * Role checks shared by the task policies. The Jetstream roles carry the
 * permissions: viewer = read, editor = read/create/update, admin = all four,
 * and the team owner passes every check (see HasTeams::hasTeamPermission()).
 *
 *   read   -> look at boards and tasks
 *   create -> add tasks and boards, move/assign/label/comment
 *   update -> same working set as create; kept separate so the matrix reads naturally
 *   delete -> archive and delete tasks and boards, manage labels
 */
trait ChecksTeamRole
{
    protected function holds(User $user, ?Team $team, string $permission): bool
    {
        return $team !== null && $user->hasTeamPermission($team, $permission);
    }
}
