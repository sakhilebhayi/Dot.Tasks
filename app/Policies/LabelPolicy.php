<?php

namespace App\Policies;

use App\Models\Label;
use App\Models\Team;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamRole;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Labels belong to a team. Admins and the owner manage them (create, rename,
 * recolour, delete); editors only put them on tasks; viewers just see them.
 */
class LabelPolicy
{
    use ChecksTeamRole;
    use HandlesAuthorization;

    /** Any member of the label's team can see its labels. */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null && $user->belongsToTeam($user->currentTeam);
    }

    public function create(User $user, Team $team): bool
    {
        return $this->holds($user, $team, 'delete');
    }

    public function update(User $user, Label $label): bool
    {
        return $this->holds($user, $label->team, 'delete');
    }

    public function delete(User $user, Label $label): bool
    {
        return $this->holds($user, $label->team, 'delete');
    }

    /** Put this label on a task: editors and up, and only a label of their own team. */
    public function assign(User $user, Label $label): bool
    {
        return $this->holds($user, $label->team, 'update');
    }
}
