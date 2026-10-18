<?php

namespace Tests\Feature;

use App\Livewire\Tasks\CreateTask;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Label;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class TaskLabelAssignmentTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function foreignLabel(): Label
    {
        $other = User::factory()->withPersonalTeam()->create();

        return Label::create(['team_id' => $other->currentTeam->id, 'name' => 'Foreign label', 'color' => '#22c55e']);
    }

    public function test_an_editor_assigns_and_unassigns_a_label_from_the_drawer(): void
    {
        $f = $this->teamFixture();

        $drawer = Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id);

        $drawer->call('toggleLabel', $f->label->id);
        $this->assertTrue($f->task->fresh()->labels->contains($f->label));

        $drawer->call('toggleLabel', $f->label->id);
        $this->assertFalse($f->task->fresh()->labels->contains($f->label));
    }

    public function test_a_viewer_cannot_assign_a_label(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->viewer)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->call('toggleLabel', $f->label->id)
            ->assertForbidden();

        $this->assertSame(0, $f->task->fresh()->labels()->count());
    }

    public function test_a_label_from_another_team_is_rejected_when_assigning(): void
    {
        $f = $this->teamFixture();
        $foreign = $this->foreignLabel();

        Livewire::actingAs($f->admin)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->call('toggleLabel', $foreign->id)
            ->assertHasErrors(['labels']);

        $this->assertSame(0, $f->task->fresh()->labels()->count());
    }

    public function test_the_drawer_only_offers_the_teams_own_labels(): void
    {
        $f = $this->teamFixture();
        $this->foreignLabel();

        Livewire::actingAs($f->editor)
            ->test(TaskDetail::class)
            ->call('openTask', $f->task->id)
            ->assertSee('Fixture label')
            ->assertDontSee('Foreign label');
    }

    public function test_a_task_is_created_with_an_assignee_and_labels(): void
    {
        $f = $this->teamFixture();
        $second = Label::create(['team_id' => $f->team->id, 'name' => 'Second label', 'color' => '#3b82f6']);

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Assigned and labelled')
            ->set('assigneeId', $f->viewer->id)
            ->set('labelIds', [$f->label->id, $second->id])
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Assigned and labelled')->firstOrFail();
        $this->assertSame($f->viewer->id, $task->assignee_id);
        $this->assertEqualsCanonicalizing([$f->label->id, $second->id], $task->labels()->pluck('labels.id')->all());
    }

    public function test_a_task_can_still_be_created_with_neither_assignee_nor_labels(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Plain task')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Plain task')->firstOrFail();
        $this->assertNull($task->assignee_id);
        $this->assertSame(0, $task->labels()->count());
    }

    public function test_an_assignee_outside_the_team_is_rejected_on_create(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Wrongly assigned')
            ->set('assigneeId', $f->outsider->id)
            ->call('save')
            ->assertHasErrors(['assigneeId']);

        $this->assertDatabaseMissing('tasks', ['title' => 'Wrongly assigned']);
    }

    public function test_a_label_from_another_team_is_rejected_on_create(): void
    {
        $f = $this->teamFixture();
        $foreign = $this->foreignLabel();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Wrongly labelled')
            ->set('labelIds', [$f->label->id, $foreign->id])
            ->call('save')
            ->assertHasErrors(['labelIds']);

        $this->assertDatabaseMissing('tasks', ['title' => 'Wrongly labelled']);
    }

    public function test_assigning_on_create_notifies_the_assignee(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Notify on create')
            ->set('assigneeId', $f->admin->id)
            ->call('save');

        $this->assertSame(1, $f->admin->notifications()->where('type', TaskAssignedNotification::class)->count());
    }

    public function test_creating_a_task_assigned_to_yourself_does_not_notify_you(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Mine')
            ->set('assigneeId', $f->editor->id)
            ->call('save');

        $this->assertSame(0, $f->editor->notifications()->count());
    }

    public function test_cards_show_their_labels_with_the_label_colour(): void
    {
        $f = $this->teamFixture();
        $f->task->labels()->attach($f->label->id);

        Livewire::actingAs($f->viewer)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->assertSee('Fixture label')
            ->assertSeeHtml('#22c55e');
    }
}
