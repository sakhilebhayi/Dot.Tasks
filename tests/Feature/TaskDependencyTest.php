<?php

namespace Tests\Feature;

use App\Livewire\Tasks\MyTasks;
use App\Livewire\Tasks\SmartViews;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class TaskDependencyTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function sibling(object $f, string $title, array $attrs = []): Task
    {
        return $f->list->tasks()->create($attrs + ['title' => $title, 'status' => 'todo']);
    }

    private function drawer(object $f, string $as = 'editor', ?Task $task = null)
    {
        return Livewire::actingAs($f->{$as})->test(TaskDetail::class)->call('openTask', ($task ?? $f->task)->id);
    }

    private function blockedBy(Task $task, Task $blocker): void
    {
        DB::table('task_dependencies')->insert(['task_id' => $task->id, 'blocked_by_id' => $blocker->id]);
    }

    public function test_an_editor_makes_a_task_blocked_by_another_on_the_same_board(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'Write the spec first');

        $this->drawer($f)->call('addBlocker', $blocker->id)->assertHasNoErrors();

        $this->assertSame([$blocker->id], $f->task->fresh()->blockedBy()->pluck('tasks.id')->all());
        $this->assertSame([$f->task->id], $blocker->fresh()->blocking()->pluck('tasks.id')->all());
    }

    public function test_a_viewer_cannot_add_or_remove_a_blocker(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'Write the spec first');

        $this->drawer($f, 'viewer')->call('addBlocker', $blocker->id)->assertForbidden();
        $this->assertSame(0, DB::table('task_dependencies')->count());

        $this->blockedBy($f->task, $blocker);
        $this->drawer($f, 'viewer')->call('removeBlocker', $blocker->id)->assertForbidden();
        $this->assertSame(1, DB::table('task_dependencies')->count());
    }

    public function test_an_editor_removes_a_blocker(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'Write the spec first');
        $this->blockedBy($f->task, $blocker);

        $this->drawer($f)->call('removeBlocker', $blocker->id)->assertHasNoErrors();

        $this->assertSame(0, DB::table('task_dependencies')->count());
    }

    public function test_a_task_cannot_block_itself(): void
    {
        $f = $this->teamFixture();

        $this->drawer($f)->call('addBlocker', $f->task->id)->assertHasErrors(['blocker']);

        $this->assertSame(0, DB::table('task_dependencies')->count());
    }

    public function test_the_blocker_must_be_on_the_same_board(): void
    {
        $f = $this->teamFixture();
        $otherBoard = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Another board']);
        $elsewhere = $otherBoard->tasks()->create(['title' => 'On another board', 'status' => 'todo']);
        $g = $this->teamFixture();

        $this->drawer($f)->call('addBlocker', $elsewhere->id)->assertHasErrors(['blocker']);
        $this->drawer($f)->call('addBlocker', $g->task->id)->assertHasErrors(['blocker']);
        $this->drawer($f)->call('addBlocker', 999999)->assertHasErrors(['blocker']);

        $this->assertSame(0, DB::table('task_dependencies')->count());
    }

    public function test_the_same_blocker_cannot_be_added_twice(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'Write the spec first');
        $this->blockedBy($f->task, $blocker);

        $this->drawer($f)->call('addBlocker', $blocker->id)->assertHasErrors(['blocker']);

        $this->assertSame(1, DB::table('task_dependencies')->count());
    }

    public function test_the_database_also_refuses_a_duplicate_pair(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'Write the spec first');
        $this->blockedBy($f->task, $blocker);

        $this->expectException(QueryException::class);
        $this->blockedBy($f->task, $blocker);
    }

    public function test_a_direct_loop_is_rejected_with_a_clear_error(): void
    {
        $f = $this->teamFixture();
        $b = $this->sibling($f, 'Task B in a loop');
        $this->blockedBy($f->task, $b); // fixture task is blocked by B

        $this->drawer($f, 'editor', $b)
            ->call('addBlocker', $f->task->id) // B blocked by the fixture task: a loop
            ->assertHasErrors(['blocker'])
            ->assertSee('loop');

        $this->assertSame(1, DB::table('task_dependencies')->count());
    }

    public function test_an_indirect_loop_is_rejected_and_nothing_is_written(): void
    {
        $f = $this->teamFixture();
        $b = $this->sibling($f, 'Task B in a chain');
        $c = $this->sibling($f, 'Task C in a chain');
        $this->blockedBy($f->task, $b); // A <- B
        $this->blockedBy($b, $c);       // B <- C

        $this->drawer($f, 'editor', $c)
            ->call('addBlocker', $f->task->id) // C <- A closes A <- B <- C <- A
            ->assertHasErrors(['blocker']);

        $this->assertSame(2, DB::table('task_dependencies')->count());
    }

    public function test_a_chain_that_is_not_a_loop_is_fine(): void
    {
        $f = $this->teamFixture();
        $b = $this->sibling($f, 'Task B in a chain');
        $c = $this->sibling($f, 'Task C in a chain');
        $this->blockedBy($f->task, $b);

        $this->drawer($f, 'editor', $b)->call('addBlocker', $c->id)->assertHasNoErrors();

        $this->assertSame(2, DB::table('task_dependencies')->count());
    }

    public function test_a_blocked_task_cannot_be_dragged_to_done(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'The unfinished blocker');
        $this->blockedBy($f->task, $blocker);

        Livewire::actingAs($f->editor)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('moveTask', $f->task->id, 'done')
            ->assertHasErrors(['status'])
            ->assertSee('The unfinished blocker')
            ->assertSee('Finish those before marking this done');

        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_blocked_task_can_still_move_to_other_columns(): void
    {
        $f = $this->teamFixture();
        $this->blockedBy($f->task, $this->sibling($f, 'The unfinished blocker'));

        Livewire::actingAs($f->editor)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('moveTask', $f->task->id, 'review')
            ->assertHasNoErrors();

        $this->assertSame('review', $f->task->fresh()->status);
    }

    public function test_a_blocked_task_cannot_be_set_done_from_the_drawer_the_smart_views_or_my_tasks(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'due_date' => now()->toDateString()]);
        $this->blockedBy($f->task, $this->sibling($f, 'The unfinished blocker'));

        $this->drawer($f)->call('updateStatus', 'done')->assertHasErrors(['status']);
        Livewire::actingAs($f->editor)->test(SmartViews::class, ['view' => 'today'])->call('completeTask', $f->task->id)->assertHasErrors(['status']);
        Livewire::actingAs($f->editor)->test(MyTasks::class)->call('completeTask', $f->task->id)->assertHasErrors(['status']);

        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_every_open_blocker_is_named_and_all_must_be_done(): void
    {
        $f = $this->teamFixture();
        $one = $this->sibling($f, 'First open blocker');
        $two = $this->sibling($f, 'Second open blocker');
        $this->blockedBy($f->task, $one);
        $this->blockedBy($f->task, $two);

        $one->update(['status' => 'done']);

        $drawer = $this->drawer($f)->call('updateStatus', 'done')->assertHasErrors(['status']);
        $message = $drawer->errors()->first('status');
        $this->assertStringContainsString('Second open blocker', $message);
        $this->assertStringNotContainsString('First open blocker', $message, 'a finished blocker is not in the way');

        $two->update(['status' => 'done']);

        $this->drawer($f)->call('updateStatus', 'done')->assertHasNoErrors();
        $this->assertSame('done', $f->task->fresh()->status);
    }

    public function test_a_failed_attempt_does_not_spawn_the_next_occurrence(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'daily', 'recurrence_interval' => 1, 'due_date' => now()->toDateString()]);
        $this->blockedBy($f->task, $this->sibling($f, 'The unfinished blocker'));

        Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->call('moveTask', $f->task->id, 'done');

        $this->assertSame(0, Task::where('recurrence_parent_id', $f->task->id)->count());
    }

    public function test_removing_the_blocker_lets_the_task_be_completed(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'The unfinished blocker');
        $this->blockedBy($f->task, $blocker);

        $drawer = $this->drawer($f);
        $drawer->call('removeBlocker', $blocker->id)->call('updateStatus', 'done')->assertHasNoErrors();

        $this->assertSame('done', $f->task->fresh()->status);
    }

    public function test_a_deleted_blocker_stops_blocking_but_an_archived_one_still_blocks(): void
    {
        $f = $this->teamFixture();
        $deleted = $this->sibling($f, 'Deleted blocker');
        $archived = $this->sibling($f, 'Archived blocker');
        $this->blockedBy($f->task, $deleted);
        $this->blockedBy($f->task, $archived);

        $deleted->delete();
        $archived->archive();

        $drawer = $this->drawer($f)->call('updateStatus', 'done')->assertHasErrors(['status']);
        $message = $drawer->errors()->first('status');
        $this->assertStringContainsString('Archived blocker', $message);
        $this->assertStringNotContainsString('Deleted blocker', $message);

        $archived->delete();
        $this->drawer($f)->call('updateStatus', 'done')->assertHasNoErrors();
    }

    public function test_the_drawer_shows_what_blocks_the_task_and_why_it_cannot_be_done(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'Approve the budget');
        $waiting = $this->sibling($f, 'Order the hardware');
        $this->blockedBy($f->task, $blocker);
        $this->blockedBy($waiting, $f->task);

        $this->drawer($f)
            ->assertSee('Blocked by')
            ->assertSee('Approve the budget')
            ->assertSee('cannot be marked done until')
            ->assertSee('Blocking')
            ->assertSee('Order the hardware');

        $this->drawer($f, 'viewer')
            ->assertSee('Approve the budget')
            ->assertDontSee('Add a blocker');
    }

    public function test_the_blocker_picker_offers_only_open_tasks_on_this_board(): void
    {
        $f = $this->teamFixture();
        $this->sibling($f, 'Pickable open sibling');
        $this->sibling($f, 'Finished sibling', ['status' => 'done']);
        $already = $this->sibling($f, 'Already a blocker');
        $this->blockedBy($f->task, $already);
        $g = $this->teamFixture();
        $g->task->update(['title' => 'Task on someone elses board']);

        $html = $this->drawer($f)->html();

        $this->assertStringContainsString('Pickable open sibling', $html);
        $this->assertStringNotContainsString('Finished sibling', $html);
        $this->assertStringNotContainsString('Task on someone elses board', $html);
        $this->assertStringContainsString('Already a blocker', $html, 'listed as a blocker');
        $this->assertStringNotContainsString('>Already a blocker</option>', $html, 'but not offered again');
        $this->assertStringContainsString('>Pickable open sibling</option>', $html);
    }

    public function test_a_card_on_the_board_says_it_is_blocked_until_the_blockers_are_done(): void
    {
        $f = $this->teamFixture();
        $blocker = $this->sibling($f, 'The unfinished blocker');
        $this->blockedBy($f->task, $blocker);

        Livewire::actingAs($f->viewer)->test(TaskBoard::class, ['taskList' => $f->list])->assertSee('Blocked');

        $blocker->update(['status' => 'done']);

        Livewire::actingAs($f->viewer)->test(TaskBoard::class, ['taskList' => $f->list])->assertDontSee('Blocked');
    }
}
