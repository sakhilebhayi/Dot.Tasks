<?php

namespace Tests\Feature;

use App\Livewire\Tasks\CreateTask;
use App\Livewire\Tasks\SmartViews;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\TaskComment;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * The roles in TaskRolesPolicyTest, proven through the real entry points:
 * the Livewire components and the board routes.
 */
class TaskRolesEnforcementTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    public function test_a_viewer_can_read_the_board_and_open_a_task(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->viewer)
            ->get(route('task-lists.show', $f->list))
            ->assertOk()
            ->assertSee('Fixture board');

        Livewire::actingAs($f->viewer)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->assertSet('task.id', $f->task->id)
            ->assertSee('Fixture task');
    }

    public function test_a_viewer_does_not_get_write_controls_on_the_board_or_the_drawer(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->viewer)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->assertDontSee('+ Add Task')
            ->assertDontSee('+ Add task');

        Livewire::actingAs($f->viewer)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->assertDontSee('Add a comment...')
            ->assertDontSee('AI Breakdown');

        Livewire::actingAs($f->editor)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->assertSee('+ Add Task');
    }

    public function test_a_viewer_cannot_move_a_task(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->viewer)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('moveTask', $f->task->id, 'done')
            ->assertForbidden();

        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_viewer_cannot_change_status_assign_or_comment_from_the_drawer(): void
    {
        $f = $this->teamFixture();

        // A fresh drawer per attempt: a component that has aborted cannot be driven further.
        $drawer = fn () => Livewire::actingAs($f->viewer)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id);

        $drawer()->call('updateStatus', 'done')->assertForbidden();
        $drawer()->call('assignTask', $f->viewer->id)->assertForbidden();
        $drawer()->set('commentBody', 'I am only reading')->call('addComment')->assertForbidden();
        $drawer()->call('aiBreakdown')->assertForbidden();

        $task = $f->task->fresh();
        $this->assertSame('todo', $task->status);
        $this->assertNull($task->assignee_id);
        $this->assertSame(0, TaskComment::count());
    }

    public function test_a_viewer_cannot_complete_a_task_from_the_smart_views(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => now()->toDateString()]);

        Livewire::actingAs($f->viewer)
            ->test(SmartViews::class, ['view' => 'today'])
            ->call('completeTask', $f->task->id)
            ->assertForbidden();

        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_viewer_cannot_create_a_task(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->viewer)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Sneaky task from a viewer')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('tasks', ['title' => 'Sneaky task from a viewer']);
    }

    public function test_a_viewer_cannot_create_a_board_but_an_editor_can(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->viewer)->get(route('task-lists.create'))->assertForbidden();
        $this->actingAs($f->viewer)
            ->post(route('task-lists.store'), ['name' => 'Viewer board'])
            ->assertForbidden();
        $this->assertDatabaseMissing('task_lists', ['name' => 'Viewer board']);

        $this->actingAs($f->editor)->get(route('task-lists.create'))->assertOk();
        $this->actingAs($f->editor)
            ->post(route('task-lists.store'), ['name' => 'Editor board'])
            ->assertRedirect();
        $this->assertDatabaseHas('task_lists', ['name' => 'Editor board', 'team_id' => $f->team->id]);
    }

    public function test_an_editor_and_an_admin_can_move_comment_and_assign(): void
    {
        foreach (['editor', 'admin'] as $role) {
            $f = $this->teamFixture();
            $user = $f->{$role};

            Livewire::actingAs($user)
                ->test(TaskBoard::class, ['taskList' => $f->list])
                ->call('moveTask', $f->task->id, 'in_progress')
                ->assertOk();
            $this->assertSame('in_progress', $f->task->fresh()->status, $role);

            $drawer = Livewire::actingAs($user)->test(TaskDetail::class)->call('openTask', $f->task->id);
            $drawer->call('assignTask', $user->id);
            $drawer->set('commentBody', "Comment from {$role}")->call('addComment');

            $this->assertSame($user->id, $f->task->fresh()->assignee_id, $role);
            $this->assertDatabaseHas('task_comments', ['body' => "Comment from {$role}", 'user_id' => $user->id]);
        }
    }

    public function test_a_user_on_another_team_gets_nothing(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->outsider)->get(route('task-lists.show', $f->list))->assertForbidden();

        Livewire::actingAs($f->outsider)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->assertForbidden();
    }

    public function test_the_task_list_model_is_not_changed_by_a_forbidden_viewer_request(): void
    {
        $f = $this->teamFixture();

        $this->assertSame(1, TaskList::count());
        $this->actingAs($f->viewer)->post(route('task-lists.store'), ['name' => 'Nope'])->assertForbidden();
        $this->assertSame(1, TaskList::count());
    }

    public function test_the_dashboard_only_offers_new_list_to_those_who_can_create_one(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('dashboard'))->assertOk()->assertSee('New List');
        $this->actingAs($f->viewer)->get(route('dashboard'))->assertOk()->assertDontSee('New List');
    }

    public function test_assigning_a_task_to_yourself_from_the_drawer_does_not_notify_you(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->call('assignTask', $f->editor->id);

        $this->assertSame($f->editor->id, $f->task->fresh()->assignee_id);
        $this->assertSame(0, $f->editor->notifications()->count());
    }

    public function test_the_new_task_form_lists_only_this_teams_people_and_labels(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->call('open', 'todo')
            ->assertSee('Fixture label')
            ->assertSee($f->viewer->name)
            ->assertDontSee($f->outsider->name);
    }
}
