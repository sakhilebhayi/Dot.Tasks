<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class TaskArchiveTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function board(object $f, string $as = 'editor', array $query = [])
    {
        return Livewire::actingAs($f->{$as})->withQueryParams($query)->test(TaskBoard::class, ['taskList' => $f->list]);
    }

    private function drawer(object $f, string $as = 'editor', ?Task $task = null)
    {
        return Livewire::actingAs($f->{$as})->test(TaskDetail::class)->call('openTask', ($task ?? $f->task)->id);
    }

    private function withSubtasks(object $f, int $count = 2): array
    {
        return collect(range(1, $count))->map(fn ($n) => $f->list->tasks()->create([
            'title' => "Subtask number {$n} of the fixture",
            'parent_id' => $f->task->id,
            'status' => 'todo',
        ]))->all();
    }

    public function test_an_editor_archives_a_task_and_it_leaves_the_board(): void
    {
        $f = $this->teamFixture();

        $this->drawer($f)->call('archiveTask')->assertHasNoErrors()->assertSet('task', null);

        $task = Task::withArchived()->find($f->task->id);
        $this->assertNotNull($task->archived_at);
        $this->assertNull(Task::find($f->task->id), 'archived tasks are hidden by default');

        $this->board($f)->assertDontSee('Fixture task');
    }

    public function test_archived_tasks_are_listed_under_archived_and_can_be_restored(): void
    {
        $f = $this->teamFixture();
        $f->task->archive();

        $this->board($f, 'viewer', ['show' => 'archived'])
            ->assertSet('show', 'archived')
            ->assertSee('Fixture task');

        $this->board($f, 'editor', ['show' => 'archived'])
            ->call('restoreTask', $f->task->id)
            ->assertHasNoErrors();

        $this->assertNull($f->task->fresh()->archived_at);
        $this->board($f)->assertSee('Fixture task');
    }

    public function test_the_archived_list_is_empty_when_nothing_is_archived(): void
    {
        $f = $this->teamFixture();

        $this->board($f, 'editor', ['show' => 'archived'])
            ->assertDontSee('Fixture task')
            ->assertSee('Nothing is archived');
    }

    public function test_a_viewer_cannot_archive_or_restore(): void
    {
        $f = $this->teamFixture();

        $this->drawer($f, 'viewer')->call('archiveTask')->assertForbidden();
        $this->assertNull($f->task->fresh()->archived_at);

        $f->task->archive();
        $this->board($f, 'viewer', ['show' => 'archived'])->call('restoreTask', $f->task->id)->assertForbidden();
        $this->assertNotNull(Task::withArchived()->find($f->task->id)->archived_at);
    }

    public function test_an_admin_deletes_a_task_softly_and_it_leaves_every_view(): void
    {
        $f = $this->teamFixture();

        $this->drawer($f, 'admin')->call('deleteTask', true)->assertHasNoErrors()->assertSet('task', null);

        $this->assertSoftDeleted('tasks', ['id' => $f->task->id]);
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);
        $this->board($f, 'admin')->assertDontSee('Fixture task');
        $this->board($f, 'admin', ['show' => 'archived'])->assertDontSee('Fixture task');
    }

    public function test_an_editor_and_a_viewer_cannot_delete(): void
    {
        $f = $this->teamFixture();

        $this->drawer($f, 'editor')->call('deleteTask', true)->assertForbidden();
        $this->drawer($f, 'viewer')->call('deleteTask', true)->assertForbidden();

        $this->assertNotSoftDeleted('tasks', ['id' => $f->task->id]);
    }

    public function test_the_drawer_offers_archive_to_editors_and_delete_only_to_admins(): void
    {
        $f = $this->teamFixture();

        $this->drawer($f, 'editor')->assertSee('Archive task')->assertDontSee('Delete task');
        $this->drawer($f, 'admin')->assertSee('Archive task')->assertSee('Delete task');
        $this->drawer($f, 'viewer')->assertDontSee('Archive task')->assertDontSee('Delete task');
    }

    public function test_archiving_a_task_with_subtasks_needs_confirmation_and_then_takes_them_along(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->withSubtasks($f);

        $drawer = $this->drawer($f);
        $drawer->call('archiveTask')->assertHasErrors(['archive']);
        $this->assertNull($f->task->fresh()->archived_at, 'nothing happens without confirmation');
        $this->assertNull($a->fresh()->archived_at);

        $drawer->call('archiveTask', true)->assertHasNoErrors();

        $parent = Task::withArchived()->find($f->task->id);
        $this->assertNotNull($parent->archived_at);
        $this->assertNotNull(Task::withArchived()->find($a->id)->archived_at);
        $this->assertNotNull(Task::withArchived()->find($b->id)->archived_at);
    }

    public function test_the_drawer_says_how_many_subtasks_are_affected(): void
    {
        $f = $this->teamFixture();
        $this->withSubtasks($f, 3);

        $this->drawer($f)
            ->call('confirmArchive')
            ->assertSet('confirming', 'archive')
            ->assertSee('also archives its 3 subtasks');
    }

    public function test_restoring_a_parent_brings_back_the_subtasks_archived_with_it_but_not_earlier_ones(): void
    {
        $f = $this->teamFixture();
        [$early, $withParent] = $this->withSubtasks($f);

        Carbon::setTestNow(Carbon::parse('2026-03-01 09:00:00'));
        $early->archive();

        Carbon::setTestNow(Carbon::parse('2026-03-05 09:00:00'));
        $this->drawer($f)->call('archiveTask', true);

        Carbon::setTestNow(Carbon::parse('2026-03-06 09:00:00'));
        $this->board($f, 'editor', ['show' => 'archived'])->call('restoreTask', $f->task->id);
        Carbon::setTestNow();

        $this->assertNull(Task::withArchived()->find($f->task->id)->archived_at);
        $this->assertNull(Task::withArchived()->find($withParent->id)->archived_at);
        $this->assertNotNull(Task::withArchived()->find($early->id)->archived_at, 'archived on its own earlier, so it stays archived');
    }

    public function test_a_subtask_cannot_be_restored_while_its_parent_is_still_archived(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->withSubtasks($f, 1);
        $this->drawer($f)->call('archiveTask', true);

        $this->board($f, 'editor', ['show' => 'archived'])
            ->call('restoreTask', $a->id)
            ->assertHasErrors(['restore']);

        $this->assertNotNull(Task::withArchived()->find($a->id)->archived_at);
    }

    public function test_deleting_a_task_with_subtasks_needs_confirmation_and_deletes_them_too(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->withSubtasks($f);

        $drawer = $this->drawer($f, 'admin');
        $drawer->call('deleteTask')->assertHasErrors(['delete']);
        $this->assertNotSoftDeleted('tasks', ['id' => $f->task->id]);

        $drawer->call('deleteTask', true)->assertHasNoErrors();

        $this->assertSoftDeleted('tasks', ['id' => $f->task->id]);
        $this->assertSoftDeleted('tasks', ['id' => $a->id]);
        $this->assertSoftDeleted('tasks', ['id' => $b->id]);
    }

    public function test_deleting_also_takes_archived_subtasks(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->withSubtasks($f, 1);
        $a->archive();

        $this->drawer($f, 'admin')->call('deleteTask', true);

        $this->assertSoftDeleted('tasks', ['id' => $a->id]);
    }

    public function test_an_archived_task_cannot_be_opened_or_moved_by_a_forged_id(): void
    {
        $f = $this->teamFixture();
        $f->task->archive();

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->assertNotFound();
        $this->board($f)->call('moveTask', $f->task->id, 'done')->assertNotFound();
        $this->assertSame('todo', Task::withArchived()->find($f->task->id)->status);
    }

    public function test_a_subtask_archived_on_its_own_disappears_from_the_parents_list(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->withSubtasks($f);
        $a->archive();

        $this->drawer($f)
            ->assertDontSee('Subtask number 1 of the fixture')
            ->assertSee('Subtask number 2 of the fixture')
            ->assertSee('Subtasks (1)');
    }

    public function test_restoring_and_archiving_another_teams_task_is_refused(): void
    {
        $f = $this->teamFixture();
        $g = $this->teamFixture();
        $g->task->archive();

        Livewire::actingAs($f->admin)->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('restoreTask', $g->task->id)
            ->assertNotFound();

        $this->assertNotNull(Task::withArchived()->find($g->task->id)->archived_at);
    }
}
