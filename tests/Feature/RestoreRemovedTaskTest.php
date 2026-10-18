<?php

namespace Tests\Feature;

use App\Events\TaskBoardUpdated;
use App\Livewire\Tasks\RemovedTasks;
use App\Models\RecordRestoration;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\TaskDependencyService;
use App\Services\TaskStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\Concerns\RemovesTasks;
use Tests\TestCase;

/**
 * Restoring a deleted task: who may, what is refused (a parent task or board
 * that is still gone), what comes back together, and what is re-derived.
 */
class RestoreRemovedTaskTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;
    use RemovesTasks;

    private function restore(object $f, Task $task, string $as = 'admin')
    {
        return Livewire::actingAs($f->{$as})->test(RemovedTasks::class)->call('restore', $task->id);
    }

    private function message($component): string
    {
        return (string) ($component->errors()->first('restore') ?: $component->get('notice'));
    }

    public function test_an_admin_restores_a_deleted_task_and_it_is_back_on_the_board(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);

        $this->restore($f, $f->task)->assertHasNoErrors();

        $this->assertNotNull(Task::find($f->task->id));
        $this->assertNull(Task::withTrashed()->find($f->task->id)->deleted_at);
        $this->assertContains($f->task->id, $f->list->tasks()->pluck('id')->all());
    }

    public function test_the_owner_can_restore_too_and_the_notice_says_what_happened(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);

        $component = $this->restore($f, $f->task, 'owner');

        $this->assertStringContainsString('Restored task "Fixture task"', $this->message($component));
    }

    public function test_restoring_a_task_brings_back_the_subtasks_removed_with_it_and_only_those(): void
    {
        $f = $this->teamFixture();
        $withIt = $this->subtask($f, $f->task, 'Removed together child');
        $grandchild = $this->subtask($f, $withIt, 'Removed together grandchild');
        $earlier = $this->subtask($f, $f->task, 'Removed earlier child');
        $this->remove($earlier, '2026-05-01 09:00:00');
        $this->remove($f->task, '2026-06-01 09:00:00');

        $component = $this->restore($f, $f->task);

        $this->assertFalse($this->isRemoved($f->task));
        $this->assertFalse($this->isRemoved($withIt));
        $this->assertFalse($this->isRemoved($grandchild));
        $this->assertTrue($this->isRemoved($earlier), 'a subtask deleted on its own earlier stays removed');
        $this->assertStringContainsString('with 2 subtasks', $this->message($component));
    }

    public function test_a_subtask_cannot_come_back_while_its_parent_task_is_removed(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['title' => 'Quarterly stock count']);
        $child = $this->subtask($f, $f->task, 'Count the pallets');
        $this->remove($f->task);
        $before = $this->fingerprint();

        $component = $this->restore($f, $child);

        $component->assertHasErrors('restore');
        $this->assertSame('The subtask "Count the pallets" cannot be restored while its parent task "Quarterly stock count" is removed. Restore the parent task first.', $component->errors()->first('restore'));
        $this->assertSame($before, $this->fingerprint());

        $this->restore($f, $f->task)->assertHasNoErrors();
        $this->assertFalse($this->isRemoved($child), 'it comes back with its parent');
    }

    public function test_the_topmost_removed_ancestor_is_the_one_named(): void
    {
        $f = $this->teamFixture();
        $middle = $this->subtask($f, $f->task, 'Middle step');
        $leaf = $this->subtask($f, $middle, 'Leaf step');
        $this->remove($leaf, '2026-04-01 09:00:00');
        $this->remove($middle, '2026-05-01 09:00:00');
        $this->remove($f->task, '2026-06-01 09:00:00');

        $component = $this->restore($f, $leaf);

        $this->assertStringContainsString('parent task "Fixture task"', $component->errors()->first('restore'));
    }

    public function test_a_subtask_under_an_archived_parent_is_refused_too(): void
    {
        $f = $this->teamFixture();
        $child = $this->subtask($f, $f->task, 'Child of an archived parent');
        $this->remove($child);
        $f->task->archive();

        $component = $this->restore($f, $child);

        $component->assertHasErrors('restore');
        $this->assertStringContainsString('is archived', $component->errors()->first('restore'));
        $this->assertTrue($this->isRemoved($child));
    }

    public function test_a_task_on_an_archived_board_waits_for_the_board(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $f->list->archive();

        $component = $this->restore($f, $f->task);

        $component->assertHasErrors('restore');
        $this->assertStringContainsString('board "Fixture board" is archived. Restore the board first.', $component->errors()->first('restore'));
        $this->assertTrue($this->isRemoved($f->task));

        $f->list->unarchive();
        $this->restore($f, $f->task)->assertHasNoErrors();
        $this->assertFalse($this->isRemoved($f->task));
    }

    public function test_a_task_that_was_archived_before_it_was_deleted_comes_back_still_archived(): void
    {
        $f = $this->teamFixture();
        $f->task->archive();
        $this->remove($f->task);

        $component = $this->restore($f, $f->task);

        $this->assertFalse($this->isRemoved($f->task));
        $this->assertNotNull(Task::withArchived()->find($f->task->id)->archived_at);
        $this->assertNull(Task::find($f->task->id), 'still hidden as archived');
        $this->assertStringContainsString('still archived', $this->message($component));
    }

    public function test_an_assignee_who_has_left_the_team_is_cleared_and_a_current_member_is_kept(): void
    {
        $f = $this->teamFixture();
        $stays = $f->list->tasks()->create(['title' => 'Assigned to a member', 'status' => 'todo', 'assignee_id' => $f->editor->id]);
        $leaver = User::factory()->create();
        $f->team->users()->attach($leaver, ['role' => 'editor']);
        $left = $f->list->tasks()->create(['title' => 'Assigned to a leaver', 'status' => 'todo', 'assignee_id' => $leaver->id]);
        $this->remove($stays);
        $this->remove($left);
        $f->team->users()->detach($leaver);

        $this->restore($f, $stays);
        $component = $this->restore($f, $left);

        $this->assertSame($f->editor->id, $stays->fresh()->assignee_id);
        $this->assertNull($left->fresh()->assignee_id);
        $this->assertStringContainsString('no longer on the team', $this->message($component));
    }

    public function test_a_restored_task_whose_blockers_were_deleted_is_not_blocked_by_them(): void
    {
        $f = $this->teamFixture();
        $blocker = $f->list->tasks()->create(['title' => 'Blocker that was deleted', 'status' => 'todo']);
        DB::table('task_dependencies')->insert(['task_id' => $f->task->id, 'blocked_by_id' => $blocker->id]);
        $this->remove($f->task);
        $this->remove($blocker);

        $this->restore($f, $f->task)->assertHasNoErrors();

        $task = $f->task->fresh();
        $this->assertSame([], $task->blockedBy()->pluck('tasks.id')->all());
        $this->assertTrue(app(TaskDependencyService::class)->openBlockers($task)->isEmpty());
        app(TaskStatusService::class)->move($task, 'done');
        $this->assertSame('done', $task->fresh()->status);
        $this->assertDatabaseHas('task_dependencies', ['task_id' => $f->task->id, 'blocked_by_id' => $blocker->id]);
    }

    public function test_restoring_a_blocker_blocks_its_waiting_tasks_again_and_says_so(): void
    {
        $f = $this->teamFixture();
        $waiting = $f->list->tasks()->create(['title' => 'Waiting on the blocker', 'status' => 'todo']);
        DB::table('task_dependencies')->insert(['task_id' => $waiting->id, 'blocked_by_id' => $f->task->id]);
        $this->remove($f->task);
        $this->assertTrue(app(TaskDependencyService::class)->openBlockers($waiting->fresh())->isEmpty());

        $component = $this->restore($f, $f->task);

        $this->assertSame([$f->task->id], app(TaskDependencyService::class)->openBlockers($waiting->fresh())->pluck('id')->all());
        $this->assertStringContainsString('1 task is waiting on it again', $this->message($component));
    }

    public function test_a_blocker_that_is_still_removed_does_not_count_as_waiting(): void
    {
        $f = $this->teamFixture();
        $waiting = $f->list->tasks()->create(['title' => 'Waiting and removed', 'status' => 'todo']);
        DB::table('task_dependencies')->insert(['task_id' => $waiting->id, 'blocked_by_id' => $f->task->id]);
        $this->remove($waiting);
        $this->remove($f->task);

        $component = $this->restore($f, $f->task);

        $this->assertStringNotContainsString('waiting on it', $this->message($component));
    }

    public function test_restoring_a_completed_recurring_task_does_not_spawn_another_occurrence(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date', 'due_date' => '2026-06-01']);
        app(TaskStatusService::class)->move($f->task->fresh(), 'done');
        $this->assertSame(2, Task::query()->where('title', 'Fixture task')->count());
        $this->remove($f->task);
        $countBefore = Task::withTrashed()->count();

        $this->restore($f, $f->task)->assertHasNoErrors();

        $this->assertSame($countBefore, Task::withTrashed()->count());
    }

    public function test_a_restored_recurring_task_resumes_its_series_when_it_is_completed(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date', 'due_date' => '2026-06-01']);
        $this->remove($f->task);

        app(TaskStatusService::class)->move(Task::withTrashed()->find($f->task->id), 'done');
        $this->assertSame(0, Task::query()->whereNotNull('recurrence_parent_id')->count(), 'a deleted source does not keep spawning');

        $this->restore($f, $f->task)->assertHasNoErrors();
        $task = $f->task->fresh();
        $task->update(['status' => 'todo']);
        app(TaskStatusService::class)->move($task, 'done');

        $next = Task::query()->where('recurrence_parent_id', $f->task->id)->first();
        $this->assertNotNull($next);
        $this->assertSame('2026-06-08', $next->due_date->toDateString());
    }

    public function test_restoring_sends_no_notification_and_broadcasts_a_board_refresh(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id]);
        $this->remove($f->task);
        Notification::fake();
        Event::fake([TaskBoardUpdated::class]);

        $this->restore($f, $f->task)->assertHasNoErrors();

        Notification::assertNothingSent();
        Event::assertDispatched(TaskBoardUpdated::class, fn ($e) => $e->taskList->id === $f->list->id);
    }

    public function test_a_restore_writes_one_append_only_audit_row(): void
    {
        $f = $this->teamFixture();
        $this->subtask($f, $f->task, 'Audit child');
        $this->remove($f->task);

        $this->restore($f, $f->task, 'owner');

        $this->assertDatabaseCount('record_restorations', 1);
        $row = RecordRestoration::first();
        $this->assertSame($f->team->id, $row->team_id);
        $this->assertSame($f->owner->id, $row->user_id);
        $this->assertSame('task', $row->record_type);
        $this->assertSame($f->task->id, $row->record_id);
        $this->assertSame('Fixture task', $row->record_label);
        $this->assertSame(1, $row->restored_with);

        $this->expectException(\LogicException::class);
        $row->update(['record_label' => 'rewritten']);
    }

    public function test_an_audit_row_cannot_be_deleted_through_the_model(): void
    {
        $f = $this->teamFixture();
        $row = RecordRestoration::create(['team_id' => $f->team->id, 'user_id' => null, 'record_type' => 'task', 'record_id' => 1, 'record_label' => 'x', 'restored_with' => 0]);

        $this->expectException(\LogicException::class);
        $row->delete();
    }

    public function test_a_subtask_restore_is_audited_as_a_subtask(): void
    {
        $f = $this->teamFixture();
        $child = $this->subtask($f, $f->task, 'Audited subtask');
        $this->remove($child);

        $this->restore($f, $child)->assertHasNoErrors();

        $this->assertSame('subtask', RecordRestoration::first()->record_type);
    }

    public function test_a_refused_restore_leaves_no_audit_row(): void
    {
        $f = $this->teamFixture();
        $child = $this->subtask($f, $f->task, 'Refused child');
        $this->remove($f->task);

        $this->restore($f, $child);

        $this->assertDatabaseCount('record_restorations', 0);
    }

    public function test_restoring_twice_or_restoring_a_live_task_is_a_404(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);

        $this->restore($f, $f->task)->assertHasNoErrors();
        $this->restore($f, $f->task)->assertNotFound();

        $live = $f->list->tasks()->create(['title' => 'Never removed', 'status' => 'todo']);
        $this->restore($f, $live)->assertNotFound();
        $this->assertDatabaseCount('record_restorations', 1);
    }

    public function test_editors_and_viewers_cannot_restore_and_nothing_changes(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $before = $this->fingerprint();

        foreach (['editor', 'viewer'] as $role) {
            $this->restore($f, $f->task, $role)->assertForbidden();
        }

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_a_board_other_than_the_default_works_the_same(): void
    {
        $f = $this->teamFixture();
        $board = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Warehouse board']);
        $task = $board->tasks()->create(['title' => 'Task on the warehouse board', 'status' => 'todo']);
        $this->remove($task);

        $this->restore($f, $task)->assertHasNoErrors();

        $this->assertFalse($this->isRemoved($task));
    }
}
