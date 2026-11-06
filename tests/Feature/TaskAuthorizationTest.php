<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_view_a_task_list_belonging_to_their_own_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Sprint Board',
        ]);

        $this->actingAs($owner)
            ->get(route('task-lists.show', $taskList))
            ->assertOk()
            ->assertSee('Sprint Board');
    }

    public function test_a_user_cannot_view_a_task_list_belonging_to_another_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Private Board',
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('task-lists.show', $taskList))
            ->assertNotFound();
    }

    public function test_a_user_cannot_move_a_task_on_another_teams_board(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Protected Board',
        ]);
        $task = $taskList->tasks()->create([
            'title' => 'Do the thing',
            'status' => 'todo',
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($outsider)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->assertNotFound();
    }

    /**
     * This is the core regression test for the fix: TaskDetail::openTask()
     * previously loaded any task ID dispatched from the client via the
     * `open-task` Livewire event with no team check at all, so a user from
     * one team could open, comment on, or AI-breakdown a task belonging to
     * a completely different team.
     */
    public function test_a_user_cannot_open_a_task_belonging_to_another_team_via_the_open_task_event(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Owner Board',
        ]);
        $task = $taskList->tasks()->create([
            'title' => 'Confidential task',
            'status' => 'todo',
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($outsider)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->assertNotFound();
    }

    public function test_a_user_can_open_a_task_belonging_to_their_own_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Owner Board',
        ]);
        $task = $taskList->tasks()->create([
            'title' => 'Visible task',
            'status' => 'todo',
        ]);

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->assertSet('task.id', $task->id);
    }
}
