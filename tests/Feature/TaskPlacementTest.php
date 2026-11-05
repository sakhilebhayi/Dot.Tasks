<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\TaskList;
use App\Services\TaskPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §5: a card stays where it was put, and a move that cannot stand says why and changes nothing. */
class TaskPlacementTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    /** @return list<Task> */
    private function cards(object $f, array $titles, string $status = 'todo'): array
    {
        return array_map(fn (string $title) => $f->list->tasks()->create(['title' => $title, 'status' => $status]), $titles);
    }

    /** @return list<string> */
    private function column(object $f, string $status = 'todo'): array
    {
        return $f->list->tasks()->where('status', $status)->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')->pluck('title')->all();
    }

    private function board(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskBoard::class, ['taskList' => $f->list]);
    }

    public function test_a_card_goes_where_it_was_dropped_in_its_own_column(): void
    {
        $f = $this->teamFixture();
        [$a, $b, $c] = $this->cards($f, ['A', 'B', 'C']);

        $this->board($f)->call('place', $c->id, 'todo', $a->id, 'todo')->assertReturned(['ok' => true]);

        $this->assertSame(['Fixture task', 'C', 'A', 'B'], $this->column($f));
    }

    public function test_no_card_after_it_means_the_foot_of_the_column(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A', 'B']);

        $this->board($f)->call('place', $a->id, 'todo', null, 'todo');

        $this->assertSame(['Fixture task', 'B', 'A'], $this->column($f));
    }

    public function test_a_card_lands_between_two_in_another_column_and_stays_after_a_fresh_read(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);
        [$x, $y] = $this->cards($f, ['X', 'Y'], 'review');

        $this->board($f)->call('place', $a->id, 'review', $y->id, 'todo')->assertReturned(['ok' => true]);

        $this->assertSame(['X', 'A', 'Y'], $this->column($f, 'review'));
        $this->assertSame('review', $a->fresh()->status);

        $seen = Livewire::actingAs($f->admin)->test(TaskBoard::class, ['taskList' => $f->list])->instance()->tasksByStatus['review'];
        $this->assertSame(['X', 'A', 'Y'], $seen->pluck('title')->all());
    }

    public function test_only_a_status_change_is_an_edit(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->cards($f, ['A', 'B']);
        DB::table('tasks')->update(['updated_at' => '2026-01-01 00:00:00']);

        $this->board($f)->call('place', $b->id, 'todo', $a->id, 'todo');

        $this->assertSame('2026-01-01', $a->fresh()->updated_at->toDateString());
        $this->assertSame('2026-01-01', $b->fresh()->updated_at->toDateString());
        $this->assertSame(0, $b->activities()->count());
    }

    public function test_a_card_that_left_while_this_one_was_in_the_air_does_not_lose_it(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->cards($f, ['A', 'B']);
        $gone = $b->id;
        $b->delete();

        $this->board($f)->call('place', $a->id, 'review', $gone, 'todo')->assertReturned(['ok' => true]);

        $this->assertSame(['A'], $this->column($f, 'review'));
    }

    public function test_a_card_someone_else_moved_stays_where_they_put_it(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);
        $board = $this->board($f);
        $a->update(['status' => 'in_progress']);

        $board->call('place', $a->id, 'review', null, 'todo')
            ->assertReturned(['ok' => false, 'message' => 'Someone else moved that card, so here is where it is now.'])
            ->assertHasErrors('status');

        $this->assertSame('in_progress', $a->fresh()->status);
    }

    public function test_a_card_that_went_away_while_it_was_held_is_explained(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);
        $board = $this->board($f);
        $a->archive();

        $board->call('place', $a->id, 'review', null, 'todo')->assertReturned(['ok' => false, 'message' => 'That card is no longer on this board.']);
    }

    public function test_a_blocked_card_cannot_be_dropped_on_done_and_nothing_moves(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->cards($f, ['A', 'First']);
        [$x] = $this->cards($f, ['X'], 'done');
        DB::table('task_dependencies')->insert(['task_id' => $a->id, 'blocked_by_id' => $b->id]);
        DB::table('tasks')->update(['sort_order' => 7]);

        $this->board($f)->call('place', $a->id, 'done', $x->id, 'todo')
            ->assertReturned(['ok' => false, 'message' => 'Blocked by "First". Finish those before marking this done.']);

        $this->assertSame('todo', $a->fresh()->status);
        $this->assertSame(7, $a->fresh()->sort_order);
        $this->assertSame(7, $x->fresh()->sort_order);
    }

    public function test_a_card_on_another_board_cannot_be_placed_through_this_one(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $theirs = $other->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $this->board($f)->call('place', $theirs->id, 'done')->assertReturned(['ok' => false, 'message' => 'That card is no longer on this board.']);

        $this->assertSame('todo', $theirs->fresh()->status);
    }

    public function test_a_viewer_cannot_place_a_card(): void
    {
        $f = $this->teamFixture();

        $this->board($f, 'viewer')->call('place', $f->task->id, 'done')->assertForbidden();

        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_subtask_is_never_counted_as_part_of_a_column(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);
        $sub = $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'parent_id' => $a->id, 'sort_order' => 9]);

        app(TaskPlacement::class)->place($a, 'todo', $f->task->id);

        $this->assertSame(9, $sub->fresh()->sort_order);
    }

    public function test_reordering_inside_done_does_not_make_a_new_occurrence(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->cards($f, ['A', 'B'], 'done');
        $b->update(['recurrence' => 'weekly', 'due_date' => '2026-11-10', 'recurrence_interval' => 1]);

        $this->board($f)->call('place', $b->id, 'done', $a->id, 'done');

        $this->assertSame(0, Task::where('recurrence_source_id', $b->id)->count());
    }

    public function test_a_finished_card_moved_back_is_reopened(): void
    {
        $f = $this->teamFixture();
        [$x] = $this->cards($f, ['X'], 'done');
        $x->update(['completed_at' => now()]);

        $this->board($f)->call('place', $x->id, 'todo', null, 'done');

        $this->assertNull($x->fresh()->completed_at);
    }

    public function test_lanes_by_person_show_who_holds_what_and_a_drop_reassigns(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);

        $board = $this->board($f)->set('lanes', 'assignee')->assertSee('Unassigned')->assertSee('Admin Person Fixture');
        $board->call('place', $a->id, 'todo', null, 'todo', (string) $f->admin->id)->assertReturned(['ok' => true]);

        $this->assertSame($f->admin->id, $a->fresh()->assignee_id);
        $this->assertSame(1, $a->activities()->where('kind', 'people')->count());

        $board->call('place', $a->id, 'todo', null, 'todo', 'none');
        $this->assertNull($a->fresh()->assignee_id);
    }

    public function test_a_drop_into_a_lane_for_a_stranger_is_refused(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);

        $this->board($f)->set('lanes', 'assignee')
            ->call('place', $a->id, 'review', null, 'todo', (string) $f->outsider->id)
            ->assertReturned(['ok' => false, 'message' => 'That person is not on this team.']);

        $this->assertNull($a->fresh()->assignee_id);
        $this->assertSame('todo', $a->fresh()->status, 'the column does not change either');
    }

    public function test_lanes_by_priority_change_the_priority(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);

        $this->board($f)->set('lanes', 'priority')->call('place', $a->id, 'todo', null, 'todo', 'urgent');

        $this->assertSame('urgent', $a->fresh()->priority);
    }

    public function test_a_lane_is_ignored_when_the_board_has_none(): void
    {
        $f = $this->teamFixture();
        [$a] = $this->cards($f, ['A']);

        $this->board($f)->call('place', $a->id, 'todo', null, 'todo', (string) $f->admin->id);

        $this->assertNull($a->fresh()->assignee_id);
    }

    public function test_each_card_sits_in_exactly_one_lane(): void
    {
        $f = $this->teamFixture();
        [$a, $b] = $this->cards($f, ['A', 'B']);
        $a->update(['assignee_id' => $f->admin->id, 'priority' => 'high']);

        $people = $this->board($f)->set('lanes', 'assignee')->instance()->swimlanes;
        $priorities = $this->board($f)->set('lanes', 'priority')->instance()->swimlanes;

        $this->assertSame(3, collect($people)->sum('count'));
        $this->assertSame(3, collect($priorities)->sum('count'));
        $this->assertSame(1, collect($priorities)->firstWhere('key', 'high')['count']);
    }

    public function test_subtasks_keep_the_order_they_are_given(): void
    {
        $f = $this->teamFixture();
        [$a, $b, $c] = array_map(fn (string $t) => $f->list->tasks()->create(['title' => $t, 'status' => 'todo', 'parent_id' => $f->task->id]), ['A', 'B', 'C']);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('placeSubtask', $c->id, $a->id);

        $this->assertSame(['C', 'A', 'B'], $f->task->subtasks()->pluck('title')->all());
    }

    public function test_only_this_tasks_subtasks_can_be_reordered_and_not_by_a_viewer(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Other', 'status' => 'todo']);
        $sub = $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'parent_id' => $f->task->id]);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('placeSubtask', $other->id)->assertNotFound();
        Livewire::actingAs($f->viewer)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('placeSubtask', $sub->id)->assertForbidden();
    }
}
