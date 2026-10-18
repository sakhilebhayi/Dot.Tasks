<?php

namespace Tests\Feature;

use App\Broadcasting\TaskListChannelAuthorizer;
use App\Events\TaskBoardUpdated;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class TaskBoardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function taskList(User $owner): TaskList
    {
        return TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Board',
        ]);
    }

    public function test_moving_a_task_on_the_board_broadcasts_a_board_updated_event(): void
    {
        Event::fake([TaskBoardUpdated::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create(['title' => 'Ship the feature', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->call('moveTask', $task->id, 'in_progress');

        Event::assertDispatched(TaskBoardUpdated::class, fn ($event) => $event->taskList->is($taskList));
    }

    public function test_updating_status_from_the_detail_modal_also_broadcasts(): void
    {
        Event::fake([TaskBoardUpdated::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create(['title' => 'Ship the feature', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('updateStatus', 'in_progress');

        Event::assertDispatched(TaskBoardUpdated::class, fn ($event) => $event->taskList->is($taskList));
    }

    public function test_refresh_board_invalidates_the_cached_computed_task_list(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Existing task', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->assertSee('Existing task')
            ->call('refreshBoard')
            ->assertSee('Existing task');
    }

    // -----------------------------------------------------------------------
    // Channel authorization
    // -----------------------------------------------------------------------

    public function test_a_team_member_is_authorized_on_their_task_lists_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);

        $this->assertTrue(TaskListChannelAuthorizer::authorize($owner, $taskList->id));
    }

    public function test_an_outsider_is_not_authorized_on_someone_elses_task_list_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);

        $this->assertFalse(TaskListChannelAuthorizer::authorize($outsider, $taskList->id));
    }

    public function test_authorization_is_false_for_a_nonexistent_task_list(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->assertFalse(TaskListChannelAuthorizer::authorize($user, 999999));
    }

    // -----------------------------------------------------------------------
    // Channel authorization via the real /broadcasting/auth endpoint
    //
    // The tests above unit-test TaskListChannelAuthorizer directly (the
    // "null" broadcast driver this app's tests force via phpunit.xml never
    // actually invokes a channel's callback through /broadcasting/auth, so
    // that endpoint alone can't verify the authorizer). But the
    // malformed-identifier fail-closed check below lives in the
    // routes/channels.php closure itself, not in the authorizer class --
    // unit-testing the authorizer wouldn't exercise it. These hit the real
    // endpoint, switching to a real Pusher-protocol driver first.
    // -----------------------------------------------------------------------

    private function authRequest(User $user, string $channelName)
    {
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');

        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_team_member_can_authorize_task_list_channel_via_the_real_endpoint(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);

        $this->authRequest($owner, "private-task-list.{$taskList->id}")
            ->assertOk();
    }

    public function test_outsider_cannot_authorize_task_list_channel_via_the_real_endpoint(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);

        $this->authRequest($outsider, "private-task-list.{$taskList->id}")
            ->assertForbidden();
    }

    public function test_non_numeric_task_list_identifier_fails_closed_rather_than_erroring(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->authRequest($user, 'private-task-list.not-a-number')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_task_list_channel(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);

        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');

        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-task-list.{$taskList->id}",
        ])->assertForbidden();
    }
}
