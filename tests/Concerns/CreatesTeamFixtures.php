<?php

namespace Tests\Concerns;

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;

/**
 * One team with a member of every role, an outsider on a different team, a
 * board and a task -- what every role-by-action test needs.
 */
trait CreatesTeamFixtures
{
    /**
     * @return object{owner: User, admin: User, editor: User, viewer: User, outsider: User, team: Team, list: TaskList, task: Task, label: Label}
     */
    protected function teamFixture(): object
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Olivia Owner Fixture']);
        $team = $owner->currentTeam;

        $members = [];
        foreach (['admin', 'editor', 'viewer'] as $role) {
            $member = User::factory()->create(['name' => ucfirst($role).' Person Fixture']);
            $team->users()->attach($member, ['role' => $role]);
            $member->switchTeam($team);
            $members[$role] = $member->fresh();
        }

        $outsider = User::factory()->withPersonalTeam()->create(['name' => 'Otto Outsider Fixture']);

        $list = TaskList::create([
            'team_id' => $team->id,
            'owner_id' => $owner->id,
            'name' => 'Fixture board',
        ]);
        $task = $list->tasks()->create(['title' => 'Fixture task', 'status' => 'todo']);
        $label = Label::create(['team_id' => $team->id, 'name' => 'Fixture label', 'color' => '#22c55e']);

        return (object) [
            'owner' => $owner,
            'admin' => $members['admin'],
            'editor' => $members['editor'],
            'viewer' => $members['viewer'],
            'outsider' => $outsider,
            'team' => $team->fresh(),
            'list' => $list,
            'task' => $task,
            'label' => $label,
        ];
    }
}
