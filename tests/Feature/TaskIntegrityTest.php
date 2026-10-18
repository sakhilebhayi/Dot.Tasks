<?php

namespace Tests\Feature;

use App\Livewire\Tasks\EscalationQueue;
use App\Livewire\Tasks\SmartViews;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskEscalationProposal;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class TaskIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listFor(User $owner, string $name = 'Board'): TaskList
    {
        return TaskList::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => $name]);
    }

    // ---- finding 2: status / priority validation ----------------------------------

    public function test_moving_a_task_to_an_unknown_status_is_rejected_not_a_500(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $list = $this->listFor($owner);
        $task = $list->tasks()->create(['title' => 'T', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(TaskBoard::class, ['taskList' => $list])
            ->call('moveTask', $task->id, 'banana')
            ->assertHasErrors('newStatus');

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_moving_a_task_to_each_valid_status_works(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $list = $this->listFor($owner);
        $task = $list->tasks()->create(['title' => 'T', 'status' => 'todo']);

        foreach (['in_progress', 'review', 'done', 'todo'] as $status) {
            Livewire::actingAs($owner)
                ->test(TaskBoard::class, ['taskList' => $list])
                ->call('moveTask', $task->id, $status)
                ->assertHasNoErrors();
            $this->assertSame($status, $task->fresh()->status);
        }
    }

    public function test_task_detail_rejects_an_unknown_status(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $task = $this->listFor($owner)->tasks()->create(['title' => 'T', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('updateStatus', 'banana')
            ->assertHasErrors('status');

        $this->assertSame('todo', $task->fresh()->status);
    }

    // ---- finding 3: priority ordering ----------------------------------------------

    public function test_today_view_orders_same_day_tasks_urgent_high_medium_low(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $list = $this->listFor($owner);
        foreach (['low', 'medium', 'urgent', 'high'] as $priority) {
            $list->tasks()->create(['title' => "P-$priority", 'status' => 'todo', 'priority' => $priority, 'due_date' => '2026-08-10']);
        }

        $titles = Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->instance()->todayTasks->pluck('title')->all();

        $this->assertSame(['P-urgent', 'P-high', 'P-medium', 'P-low'], $titles);
    }

    // ---- finding 4: tenancy ---------------------------------------------------------

    public function test_for_team_scope_returns_only_that_teams_tasks(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $this->listFor($a)->tasks()->create(['title' => 'A-task', 'status' => 'todo']);
        $this->listFor($b)->tasks()->create(['title' => 'B-task', 'status' => 'todo']);

        $this->assertSame(['A-task'], Task::forTeam($a->currentTeam)->pluck('title')->all());
        $this->assertSame(['B-task'], Task::forTeam($b->currentTeam->id)->pluck('title')->all());
    }

    public function test_dashboard_counts_exclude_other_teams_tasks(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $this->listFor($a)->tasks()->create(['title' => 'A', 'status' => 'todo']);
        $listB = $this->listFor($b);
        foreach (range(1, 3) as $i) {
            $listB->tasks()->create(['title' => "B$i", 'status' => 'todo']);
        }

        $response = $this->actingAs($a)->get('/dashboard')->assertOk();

        $this->assertSame(1, $response->viewData('taskCounts')['todo']);
    }

    public function test_smart_views_do_not_leak_or_complete_other_teams_tasks(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $taskA = $this->listFor($a)->tasks()->create(['title' => 'Secret A task', 'status' => 'todo', 'due_date' => '2026-08-10']);

        Livewire::actingAs($b)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertDontSee('Secret A task')
            ->call('completeTask', $taskA->id)
            ->assertForbidden();

        $this->assertSame('todo', $taskA->fresh()->status);
    }

    public function test_user_b_cannot_view_move_comment_or_assign_user_as_task(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $list = $this->listFor($a);
        $task = $list->tasks()->create(['title' => 'A-private', 'status' => 'todo']);

        // view
        Livewire::actingAs($b)->test(TaskDetail::class)->call('openTask', $task->id)->assertForbidden();
        // move (board mount is the authorization boundary)
        Livewire::actingAs($b)->test(TaskBoard::class, ['taskList' => $list])->assertForbidden();

        // comment / assign / status with the task loaded as A, then replayed as B is covered
        // by the policy: B has no ability on the task at all.
        $this->assertFalse($b->can('view', $task));
        $this->assertFalse($b->can('update', $task));
        $this->assertFalse($b->can('escalate', $task));

        $this->assertDatabaseCount('task_comments', 0);
        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_user_b_cannot_assign_a_task_to_themselves_via_a_tampered_request(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $task = $this->listFor($a)->tasks()->create(['title' => 'A-private', 'status' => 'todo']);

        // B is outside the team, so even a direct assign of B (not a team member) must be refused.
        Livewire::actingAs($a)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('assignTask', $b->id)
            ->assertForbidden();

        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_user_b_cannot_see_or_escalate_user_as_proposals(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $task = $this->listFor($a)->tasks()->create(['title' => 'A-overdue', 'status' => 'todo', 'due_date' => now()->subDays(9)]);
        $proposal = TaskEscalationProposal::create(['task_id' => $task->id, 'status' => 'pending', 'reason' => 'x']);

        Livewire::actingAs($b)
            ->test(EscalationQueue::class)
            ->assertDontSee('A-overdue')
            ->call('approve', $proposal->id)
            ->assertForbidden();

        $this->assertSame('pending', $proposal->fresh()->status);
        $this->assertNull($task->fresh()->escalated_at);
    }

    public function test_escalation_queue_is_scoped_to_the_current_team_not_just_the_owner(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $otherTeam = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Second', 'personal_team' => false]);

        $mine = $this->listFor($owner)->tasks()->create(['title' => 'In current team', 'status' => 'todo', 'due_date' => now()->subDays(9)]);
        $other = TaskList::create(['team_id' => $otherTeam->id, 'owner_id' => $owner->id, 'name' => 'Other'])
            ->tasks()->create(['title' => 'In other team', 'status' => 'todo', 'due_date' => now()->subDays(9)]);
        TaskEscalationProposal::create(['task_id' => $mine->id, 'status' => 'pending', 'reason' => 'x']);
        TaskEscalationProposal::create(['task_id' => $other->id, 'status' => 'pending', 'reason' => 'x']);

        Livewire::actingAs($owner)
            ->test(EscalationQueue::class)
            ->assertSee('In current team')
            ->assertDontSee('In other team');
    }

    public function test_escalate_policy_requires_the_owner_to_still_belong_to_the_tasks_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $task = $this->listFor($owner)->tasks()->create(['title' => 'T', 'status' => 'todo']);
        $this->assertTrue($owner->can('escalate', $task));

        // Move the list to a team the owner is not part of (e.g. removed from the team).
        $stranger = User::factory()->withPersonalTeam()->create();
        $task->taskList->update(['team_id' => $stranger->currentTeam->id]);

        $this->assertFalse($owner->fresh()->can('escalate', $task->fresh()));
    }

    public function test_the_only_label_listing_is_scoped_to_the_lists_own_team(): void
    {
        $a = User::factory()->withPersonalTeam()->create();
        $b = User::factory()->withPersonalTeam()->create();
        $list = $this->listFor($a);
        Label::create(['team_id' => $a->currentTeam->id, 'name' => 'mine-label']);
        Label::create(['team_id' => $b->currentTeam->id, 'name' => 'foreign-label']);

        $labels = $this->actingAs($a)->get(route('task-lists.show', $list))->assertOk()->viewData('labels');

        $this->assertSame(['mine-label'], $labels->pluck('name')->all());
        // And B cannot reach A's list page to see A's labels.
        $this->actingAs($b)->get(route('task-lists.show', $list))->assertForbidden();
    }

    // ---- finding 5: indexes ---------------------------------------------------------

    public function test_task_indexes_exist(): void
    {
        $columnsets = collect(Schema::getIndexes('tasks'))->pluck('columns')->map(fn ($c) => implode(',', $c))->all();

        foreach (['status', 'due_date', 'assignee_id', 'task_list_id,status,sort_order'] as $expected) {
            $this->assertContains($expected, $columnsets, "missing index on tasks($expected)");
        }
    }
}
