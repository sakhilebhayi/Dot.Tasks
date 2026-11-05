<?php

namespace App\Services;

use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;

/** Every person has a private Inbox in each team, so a task never needs a board first. */
class InboxLists
{
    public function for(User $user, Team $team): TaskList
    {
        return TaskList::firstOrCreate(
            ['team_id' => $team->id, 'owner_id' => $user->id, 'is_inbox' => true],
            ['name' => 'Inbox', 'color' => '#5e605a'],
        );
    }
}
