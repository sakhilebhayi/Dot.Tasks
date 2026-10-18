<?php

namespace Tests\Feature;

use App\Livewire\Tasks\CreateTask;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Label;
use App\Models\TaskList;
use App\Models\User;
use App\Services\TaskRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TaskRecurrenceTest extends TestCase
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

    public function test_creating_a_task_with_a_recurrence_rule_persists_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);

        Livewire::actingAs($owner)
            ->test(CreateTask::class, ['taskList' => $taskList])
            ->set('title', 'Water the plants')
            ->set('recurrenceType', 'weekly')
            ->set('recurrenceInterval', 2)
            ->set('recurrenceAnchor', 'completion')
            ->call('save');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Water the plants',
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 2,
            'recurrence_anchor' => 'completion',
        ]);
    }

    public function test_completing_a_task_via_the_board_spawns_the_next_occurrence(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Daily standup notes',
            'status' => 'todo',
            'due_date' => '2026-08-10',
            'recurrence_type' => 'daily',
            'recurrence_interval' => 1,
            'recurrence_anchor' => 'due_date',
        ]);

        Livewire::actingAs($owner)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->call('moveTask', $task->id, 'done');

        $this->assertSame('done', $task->fresh()->status);

        $occurrence = $taskList->tasks()->where('id', '!=', $task->id)->first();
        $this->assertNotNull($occurrence);
        $this->assertSame('todo', $occurrence->status);
        $this->assertSame('2026-08-11', $occurrence->due_date->toDateString());
        $this->assertSame($task->id, $occurrence->recurrence_parent_id);
    }

    public function test_completing_a_task_via_the_detail_modal_also_spawns_the_next_occurrence(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Weekly report',
            'status' => 'todo',
            'due_date' => '2026-08-10',
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
        ]);

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('updateStatus', 'done');

        $this->assertSame(2, $taskList->tasks()->count());
    }

    public function test_completing_a_non_recurring_task_spawns_nothing(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create(['title' => 'One-off task', 'status' => 'todo']);

        Livewire::actingAs($owner)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->call('moveTask', $task->id, 'done');

        $this->assertSame(1, $taskList->tasks()->count());
    }

    public function test_moving_a_recurring_task_to_a_non_done_status_does_not_spawn_an_occurrence(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Daily standup notes',
            'status' => 'todo',
            'recurrence_type' => 'daily',
        ]);

        Livewire::actingAs($owner)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->call('moveTask', $task->id, 'in_progress');

        $this->assertSame(1, $taskList->tasks()->count());
    }

    public function test_a_second_completion_chains_to_the_same_series_parent(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Daily standup notes',
            'status' => 'todo',
            'due_date' => '2026-08-10',
            'recurrence_type' => 'daily',
            'recurrence_interval' => 1,
        ]);

        $service = app(TaskRecurrenceService::class);
        $first = $service->spawnNextOccurrence($task);
        $first->update(['status' => 'done']);
        $second = $service->spawnNextOccurrence($first->fresh());

        $this->assertSame($task->id, $first->recurrence_parent_id);
        $this->assertSame($task->id, $second->recurrence_parent_id);
        $this->assertSame(3, $taskList->tasks()->count());
    }

    public function test_completion_anchored_recurrence_bases_the_next_due_date_on_todays_date_not_the_original_due_date(): void
    {
        Carbon::setTestNow('2026-09-01');

        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Floating task',
            'status' => 'todo',
            'due_date' => '2026-08-01', // completed weeks late
            'recurrence_type' => 'daily',
            'recurrence_interval' => 3,
            'recurrence_anchor' => 'completion',
        ]);

        $occurrence = app(TaskRecurrenceService::class)->spawnNextOccurrence($task);

        $this->assertSame('2026-09-04', $occurrence->due_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_the_spawned_occurrence_carries_over_labels(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $label = Label::create(['team_id' => $owner->currentTeam->id, 'name' => 'Chores']);
        $task = $taskList->tasks()->create([
            'title' => 'Take out the trash',
            'status' => 'todo',
            'recurrence_type' => 'weekly',
        ]);
        $task->labels()->attach($label->id);

        $occurrence = app(TaskRecurrenceService::class)->spawnNextOccurrence($task);

        $this->assertTrue($occurrence->labels->contains($label->id));
    }

    public function test_an_outsider_cannot_trigger_recurrence_spawning_via_the_board(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Daily standup notes',
            'status' => 'todo',
            'recurrence_type' => 'daily',
        ]);

        Livewire::actingAs($outsider)
            ->test(TaskBoard::class, ['taskList' => $taskList])
            ->assertForbidden();

        $this->assertSame(1, $taskList->tasks()->count());
    }
}
