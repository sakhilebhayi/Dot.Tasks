<?php

namespace Tests\Feature;

use App\Livewire\Tasks\SmartViews;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SmartViewsTest extends TestCase
{
    use RefreshDatabase;

    private function taskList(User $owner, string $name = 'Board'): TaskList
    {
        return TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => $name,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_view_includes_tasks_due_today(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Due today', 'status' => 'todo', 'due_date' => '2026-08-10']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertSee('Due today');
    }

    public function test_today_view_folds_in_overdue_tasks(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Overdue task', 'status' => 'todo', 'due_date' => '2026-08-01']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertSee('Overdue task');
    }

    public function test_today_view_excludes_tasks_due_later(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Future task', 'status' => 'todo', 'due_date' => '2026-08-15']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertDontSee('Future task');
    }

    public function test_today_view_excludes_completed_tasks(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Already done', 'status' => 'done', 'due_date' => '2026-08-10']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertDontSee('Already done');
    }

    public function test_today_view_excludes_tasks_with_no_due_date(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Someday task', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertDontSee('Someday task');
    }

    public function test_upcoming_view_includes_tasks_within_the_next_7_days(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Next week task', 'status' => 'todo', 'due_date' => '2026-08-14']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'upcoming'])
            ->assertSee('Next week task');
    }

    public function test_upcoming_view_excludes_todays_task(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Due today', 'status' => 'todo', 'due_date' => '2026-08-10']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'upcoming'])
            ->assertDontSee('Due today');
    }

    public function test_upcoming_view_excludes_tasks_beyond_7_days(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create(['title' => 'Far future task', 'status' => 'todo', 'due_date' => '2026-09-01']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'upcoming'])
            ->assertDontSee('Far future task');
    }

    public function test_views_span_every_list_on_the_team_not_just_one(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $listA = $this->taskList($owner, 'List A');
        $listB = $this->taskList($owner, 'List B');
        $listA->tasks()->create(['title' => 'Task in A', 'status' => 'todo', 'due_date' => '2026-08-10']);
        $listB->tasks()->create(['title' => 'Task in B', 'status' => 'todo', 'due_date' => '2026-08-10']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->assertSee('Task in A')
            ->assertSee('Task in B');
    }

    public function test_complete_task_marks_it_done_and_removes_it_from_today(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create(['title' => 'Finish the report', 'status' => 'todo', 'due_date' => '2026-08-10']);

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'today'])
            ->call('completeTask', $task->id)
            ->assertDontSee('Finish the report');

        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_an_outsider_cannot_complete_a_task_from_another_teams_list(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create(['title' => 'Not yours', 'status' => 'todo', 'due_date' => '2026-08-10']);

        Livewire::actingAs($outsider)
            ->test(SmartViews::class, ['view' => 'today'])
            ->call('completeTask', $task->id)
            ->assertForbidden();

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_an_invalid_view_name_404s(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($owner)
            ->test(SmartViews::class, ['view' => 'someday'])
            ->assertNotFound();
    }
}
