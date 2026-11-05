<?php

namespace Tests\Feature;

use App\Livewire\Tasks\BoardColumns;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskTable;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Services\TaskSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §7.2: a board has its own columns, and each still means one of four things. */
class BoardColumnsTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function editor(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(BoardColumns::class, ['taskList' => $f->list]);
    }

    private function board(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskBoard::class, ['taskList' => $f->list]);
    }

    private function column(object $f, string $name): TaskStatus
    {
        return $f->list->columns()->where('name', $name)->sole();
    }

    /** @return list<string> */
    private function names(object $f): array
    {
        return $f->list->columns()->pluck('name')->all();
    }

    public function test_a_new_board_starts_with_the_four_columns(): void
    {
        $f = $this->teamFixture();

        $this->assertSame(['To Do', 'In Progress', 'Review', 'Done'], $this->names($f));
        $this->assertSame($this->column($f, 'To Do')->id, $f->task->fresh()->status_id);
    }

    public function test_boards_from_before_get_the_four_columns_and_keep_their_tasks_in_place(): void
    {
        $f = $this->teamFixture();
        $done = $f->list->tasks()->create(['title' => 'Done', 'status' => 'done']);
        $migration = include database_path('migrations/2026_11_06_000004_create_task_statuses_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(['To Do', 'In Progress', 'Review', 'Done'], $this->names($f));
        $this->assertSame($this->column($f, 'Done')->id, $done->fresh()->status_id);
        $this->assertSame($this->column($f, 'To Do')->id, $f->task->fresh()->status_id);
    }

    public function test_a_column_is_added_and_appears_on_the_board(): void
    {
        $f = $this->teamFixture();

        $this->editor($f)->set('newName', 'Waiting on supplier')->set('newCategory', 'in_progress')->call('add')->assertHasNoErrors();

        $this->assertSame(['To Do', 'In Progress', 'Review', 'Done', 'Waiting on supplier'], $this->names($f));
        $this->board($f)->assertSee('Waiting on supplier');
    }

    public function test_a_card_moved_into_a_custom_column_keeps_its_meaning(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Waiting on supplier')->set('newCategory', 'in_progress')->call('add');
        $column = $this->column($f, 'Waiting on supplier');

        $this->board($f)->call('place', $f->task->id, (string) $column->id, null, (string) $f->task->status_id)->assertReturned(['ok' => true]);

        $task = $f->task->fresh();
        $this->assertSame($column->id, $task->status_id);
        $this->assertSame('in_progress', $task->status);
        $this->assertSame('Moved it to Waiting on supplier', $task->activities()->latest('id')->first()->summary);
    }

    public function test_moving_between_columns_of_one_meaning_is_still_a_move(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Second queue')->set('newCategory', 'todo')->call('add');
        $column = $this->column($f, 'Second queue');
        $f->task->update(['status' => 'done', 'completed_at' => now()]);
        $f->task->update(['status' => 'todo', 'completed_at' => null]);

        $this->board($f)->call('place', $f->task->id, (string) $column->id)->assertReturned(['ok' => true]);

        $this->assertSame($column->id, $f->task->fresh()->status_id);
        $this->assertSame('todo', $f->task->fresh()->status);
        $this->assertSame('Moved it to Second queue', $f->task->activities()->latest('id')->first()->summary);
    }

    public function test_a_custom_done_column_finishes_the_task(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Signed off')->set('newCategory', 'done')->call('add');

        $this->board($f)->call('place', $f->task->id, (string) $this->column($f, 'Signed off')->id);

        $this->assertSame('done', $f->task->fresh()->status);
        $this->assertNotNull($f->task->fresh()->completed_at);
    }

    public function test_a_blocked_card_cannot_enter_any_done_column(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Signed off')->set('newCategory', 'done')->call('add');
        $f->task->blockedBy()->attach($f->list->tasks()->create(['title' => 'First', 'status' => 'todo']));

        $this->board($f)->call('place', $f->task->id, (string) $this->column($f, 'Signed off')->id)->assertHasErrors('status');

        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_column_from_another_board_is_refused(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);

        $this->board($f)->call('place', $f->task->id, (string) $other->columns()->first()->id)->assertHasErrors('status');

        $this->assertSame($f->list->id, TaskStatus::find($f->task->fresh()->status_id)->task_list_id);
    }

    public function test_a_card_in_any_column_is_placed_from_the_list_and_the_panel_too(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Waiting')->set('newCategory', 'in_progress')->call('add');
        $column = $this->column($f, 'Waiting');

        Livewire::actingAs($f->editor)->test(TaskTable::class, ['taskList' => $f->list])
            ->assertSee('Waiting')->call('saveCell', $f->task->id, 'status', (string) $column->id);
        $this->assertSame($column->id, $f->task->fresh()->status_id);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->assertSee('Waiting')
            ->call('updateStatus', (string) $this->column($f, 'Review')->id);
        $this->assertSame('review', $f->task->fresh()->status);
    }

    public function test_a_status_changed_anywhere_keeps_its_card_in_a_matching_column(): void
    {
        $f = $this->teamFixture();

        $f->task->update(['status' => 'review']);

        $this->assertSame($this->column($f, 'Review')->id, $f->task->fresh()->status_id);
    }

    public function test_a_card_that_stays_in_its_meaning_stays_in_its_column(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Second queue')->set('newCategory', 'todo')->call('add');
        $f->task->update(['status_id' => $this->column($f, 'Second queue')->id]);

        $f->task->update(['title' => 'Renamed', 'priority' => 'high']);

        $this->assertSame($this->column($f, 'Second queue')->id, $f->task->fresh()->status_id);
    }

    public function test_a_board_without_a_review_column_still_shows_a_card_in_review(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->call('remove', $this->column($f, 'Review')->id);

        $card = $f->list->tasks()->create(['title' => 'Stray', 'status' => 'review']);

        $this->assertSame($this->column($f, 'To Do')->id, $card->fresh()->status_id);
        $this->board($f)->assertSee('Stray');
    }

    public function test_a_new_occurrence_starts_in_a_to_do_column(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date', 'due_date' => '2026-11-10']);

        $this->board($f)->call('place', $f->task->id, 'done');

        $next = Task::where('recurrence_source_id', $f->task->id)->sole();
        $this->assertSame($this->column($f, 'To Do')->id, $next->status_id);
    }

    public function test_a_duplicate_of_finished_work_starts_in_a_to_do_column(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['status' => 'done']);
        $this->actingAs($f->editor);

        $copy = app(TaskSteps::class)->duplicate($f->task->fresh());

        $this->assertSame($this->column($f, 'To Do')->id, $copy->fresh()->status_id);
    }

    public function test_a_card_moved_to_another_board_takes_a_matching_column_there(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['status' => 'review']);
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $sub = $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'parent_id' => $f->task->id]);

        $this->board($f)->call('moveToBoard', $f->task->id, $other->id);

        $this->assertSame($other->columns()->where('category', 'review')->sole()->id, $f->task->fresh()->status_id);
        $this->assertSame($other->columns()->where('category', 'todo')->sole()->id, $sub->fresh()->status_id);
    }

    public function test_a_column_is_renamed(): void
    {
        $f = $this->teamFixture();

        $this->editor($f)->call('rename', $this->column($f, 'Review')->id, 'Checking');

        $this->assertSame(['To Do', 'In Progress', 'Checking', 'Done'], $this->names($f));
    }

    public function test_two_columns_cannot_share_a_name_and_a_name_cannot_be_empty(): void
    {
        $f = $this->teamFixture();

        $this->editor($f)->call('rename', $this->column($f, 'Review')->id, 'done')->assertHasErrors('column');
        $this->editor($f)->call('rename', $this->column($f, 'Review')->id, '   ')->assertHasErrors('column');
        $this->editor($f)->set('newName', 'to do')->call('add')->assertHasErrors('column');

        $this->assertSame(['To Do', 'In Progress', 'Review', 'Done'], $this->names($f));
    }

    public function test_columns_are_put_in_order(): void
    {
        $f = $this->teamFixture();

        $this->editor($f)->call('placeColumn', $this->column($f, 'Review')->id, $this->column($f, 'To Do')->id);
        $this->assertSame(['Review', 'To Do', 'In Progress', 'Done'], $this->names($f));

        $this->editor($f)->call('placeColumn', $this->column($f, 'Review')->id);
        $this->assertSame(['To Do', 'In Progress', 'Done', 'Review'], $this->names($f));
    }

    public function test_a_removed_columns_cards_go_to_a_column_of_the_same_meaning(): void
    {
        $f = $this->teamFixture();
        $this->editor($f)->set('newName', 'Second queue')->set('newCategory', 'in_progress')->call('add');
        $queue = $this->column($f, 'Second queue');
        $f->task->update(['status' => 'in_progress', 'status_id' => $queue->id]);

        $this->editor($f)->call('remove', $queue->id);

        $this->assertSame($this->column($f, 'In Progress')->id, $f->task->fresh()->status_id);
    }

    public function test_with_no_column_of_that_meaning_left_cards_go_to_to_do_including_archived_and_removed_ones(): void
    {
        $f = $this->teamFixture();
        $review = $this->column($f, 'Review');
        $live = $f->list->tasks()->create(['title' => 'Live', 'status' => 'review']);
        $archived = $f->list->tasks()->create(['title' => 'Archived', 'status' => 'review']);
        $archived->archive();
        $removed = $f->list->tasks()->create(['title' => 'Removed', 'status' => 'review']);
        $removed->delete();

        $this->editor($f)->call('remove', $review->id);

        $todo = $this->column($f, 'To Do');
        $this->assertSame([$todo->id, 'todo'], [$live->fresh()->status_id, $live->fresh()->status]);
        $this->assertSame($todo->id, Task::withArchived()->find($archived->id)->status_id);
        $this->assertSame($todo->id, Task::withTrashed()->find($removed->id)->status_id);
    }

    public function test_a_board_keeps_a_to_do_column_and_a_done_column(): void
    {
        $f = $this->teamFixture();

        $this->editor($f)->call('remove', $this->column($f, 'To Do')->id)->assertHasErrors('column');
        $this->editor($f)->call('remove', $this->column($f, 'Done')->id)->assertHasErrors('column');

        $this->assertCount(4, $this->names($f));
    }

    public function test_a_column_with_cards_keeps_its_meaning_until_it_is_empty(): void
    {
        $f = $this->teamFixture();
        $review = $this->column($f, 'Review');

        $card = $f->list->tasks()->create(['title' => 'In it', 'status' => 'review']);

        $this->editor($f)->call('recategorize', $review->id, 'in_progress')->assertHasErrors('column');
        $this->assertSame('review', $review->fresh()->category);

        $card->update(['status' => 'todo']);
        $this->editor($f)->call('recategorize', $review->id, 'in_progress')->assertHasNoErrors();
        $this->assertSame('in_progress', $review->fresh()->category);

        $this->editor($f)->call('recategorize', $this->column($f, 'Done')->id, 'review')->assertHasErrors('column');
    }

    public function test_a_board_stops_at_twelve_columns(): void
    {
        $f = $this->teamFixture();
        foreach (range(5, 12) as $n) {
            $this->editor($f)->set('newName', 'Column '.$n)->call('add')->assertHasNoErrors();
        }

        $this->editor($f)->set('newName', 'One too many')->call('add')->assertHasErrors('column');

        $this->assertCount(12, $this->names($f));
    }

    public function test_a_viewer_cannot_change_columns_or_see_the_button(): void
    {
        $f = $this->teamFixture();
        $id = $this->column($f, 'Review')->id;

        $this->editor($f, 'viewer')->call('rename', $id, 'Nope')->assertForbidden();
        $this->editor($f, 'viewer')->call('remove', $id)->assertForbidden();
        $this->editor($f, 'viewer')->set('newName', 'Nope')->call('add')->assertForbidden();
        $this->editor($f, 'viewer')->call('placeColumn', $id)->assertForbidden();
        $this->actingAs($f->viewer)->get(route('task-lists.show', $f->list))->assertDontSeeLivewire(BoardColumns::class);

        $this->assertSame(['To Do', 'In Progress', 'Review', 'Done'], $this->names($f));
    }

    public function test_a_column_on_another_board_cannot_be_touched_from_here(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);

        $this->editor($f)->call('remove', $other->columns()->where('category', 'review')->sole()->id)->assertNotFound();

        $this->assertSame(4, $other->columns()->count());
    }

    public function test_the_board_stays_quiet_on_queries_as_columns_fill(): void
    {
        $f = $this->teamFixture();
        $count = function (int $rows) use ($f): int {
            foreach (range(1, $rows) as $n) {
                $f->list->tasks()->create(['title' => 'Row '.$n, 'status' => 'review']);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->board($f);

            return count(DB::getQueryLog());
        };

        $count(1);
        $this->assertSame($count(3), $count(30));
        DB::disableQueryLog();
    }
}
