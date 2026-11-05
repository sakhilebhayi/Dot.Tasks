<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskTable;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §7.1: the list view works on the same task records as the board. */
class TaskTableTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function table(object $f, string $who = 'editor', array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($f->{$who})->test(TaskTable::class, ['taskList' => $f->list]);
    }

    /** @return list<string> */
    private function order($table): array
    {
        return $table->instance()->tasks->pluck('title')->all();
    }

    public function test_the_list_and_the_board_are_two_views_of_one_board(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('task-lists.show', [$f->list, 'view' => 'list']))
            ->assertOk()->assertSeeLivewire(TaskTable::class)->assertDontSeeLivewire(TaskBoard::class)->assertSee('Fixture task');

        $this->actingAs($f->editor)->get(route('task-lists.show', [$f->list, 'view' => 'nonsense']))
            ->assertOk()->assertSeeLivewire(TaskBoard::class);
    }

    public function test_moving_between_views_keeps_the_filters(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('task-lists.show', [$f->list, 'view' => 'list', 'priority' => 'high', 'unrelated' => 'x']))
            ->assertSee('/lists/'.$f->list->id.'?priority=high', false)
            ->assertDontSee('unrelated=x', false);
    }

    public function test_tasks_group_by_status_person_priority_and_due_date(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-01', 'assignee_id' => $f->admin->id, 'priority' => 'urgent']);
        $f->list->tasks()->create(['title' => 'Today', 'status' => 'review', 'due_date' => '2026-11-05']);
        $f->list->tasks()->create(['title' => 'Soon', 'status' => 'todo', 'due_date' => '2026-11-12']);
        $f->list->tasks()->create(['title' => 'Later', 'status' => 'todo', 'due_date' => '2026-11-13']);
        $f->list->tasks()->create(['title' => 'Open-ended', 'status' => 'todo']);
        $f->list->tasks()->create(['title' => 'Finished', 'status' => 'done', 'due_date' => '2026-10-01']);

        $status = collect($this->table($f)->instance()->groups)->mapWithKeys(fn ($g) => [$g['name'] => $g['tasks']->count()]);
        $this->assertSame(['To Do' => 4, 'In Progress' => 0, 'Review' => 1, 'Done' => 1], $status->all());

        $due = collect($this->table($f, 'editor', ['group' => 'due'])->instance()->groups)->mapWithKeys(fn ($g) => [$g['name'] => $g['tasks']->pluck('title')->all()]);
        $this->assertSame([
            'Overdue' => ['Fixture task'], 'Today' => ['Today'], 'Next 7 days' => ['Soon'], 'Later' => ['Later'], 'No due date' => ['Open-ended'], 'Done' => ['Finished'],
        ], $due->all());

        $people = collect($this->table($f, 'editor', ['group' => 'assignee'])->instance()->groups)->mapWithKeys(fn ($g) => [$g['key'] => $g['tasks']->count()]);
        $this->assertSame(1, $people[(string) $f->admin->id]);
        $this->assertSame(5, $people['none']);

        $priority = collect($this->table($f, 'editor', ['group' => 'priority'])->instance()->groups)->firstWhere('key', 'urgent');
        $this->assertSame(['Fixture task'], $priority['tasks']->pluck('title')->all());

        $flat = $this->table($f, 'editor', ['group' => 'none'])->instance()->groups;
        $this->assertCount(1, $flat);
        $this->assertCount(6, $flat[0]['tasks']);
        Carbon::setTestNow();
    }

    public function test_a_group_from_the_address_bar_that_means_nothing_is_ignored(): void
    {
        $f = $this->teamFixture();

        $table = $this->table($f, 'editor', ['group' => 'banana', 'sort' => 'banana', 'cols' => 'banana,due']);

        $this->assertSame('status', $table->instance()->groupBy);
        $this->assertSame('manual', $table->instance()->sortBy);
        $this->assertSame(['due'], $table->instance()->shown);
    }

    public function test_the_title_the_due_date_and_the_priority_sort_either_way(): void
    {
        $f = $this->teamFixture();
        $f->task->delete();
        $f->list->tasks()->create(['title' => 'B', 'status' => 'todo', 'priority' => 'low', 'due_date' => '2026-11-02', 'sort_order' => 1]);
        $f->list->tasks()->create(['title' => 'A', 'status' => 'todo', 'priority' => 'urgent', 'due_date' => '2026-11-03', 'sort_order' => 3]);
        $f->list->tasks()->create(['title' => 'C', 'status' => 'todo', 'priority' => 'medium', 'sort_order' => 2]);

        $this->assertSame(['B', 'C', 'A'], $this->order($this->table($f)));

        $table = $this->table($f)->call('sortOn', 'title');
        $this->assertSame(['A', 'B', 'C'], $this->order($table));
        $table->call('sortOn', 'title');
        $this->assertSame(['C', 'B', 'A'], $this->order($table));
        $table->call('sortOn', 'title');
        $this->assertSame('manual', $table->get('sort'));

        $this->assertSame(['A', 'C', 'B'], $this->order($this->table($f)->call('sortOn', 'priority')));
        $this->assertSame(['B', 'A', 'C'], $this->order($this->table($f)->call('sortOn', 'due')));
        $this->assertSame(['A', 'B', 'C'], $this->order($this->table($f)->call('sortOn', 'due')->call('sortOn', 'due')), 'tasks with no date stay last either way');
    }

    public function test_filters_work_here_as_on_the_board(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Urgent one', 'status' => 'todo', 'priority' => 'urgent']);
        $f->list->tasks()->create(['title' => 'Subtask', 'status' => 'todo', 'parent_id' => $f->task->id, 'priority' => 'urgent']);

        $this->assertSame(['Urgent one'], $this->order($this->table($f, 'editor', ['priority' => 'urgent'])));
    }

    /** @return array<string, array{string, string, string}> */
    public static function cells(): array
    {
        return [
            'title' => ['title', 'New title', 'title'],
            'priority' => ['priority', 'high', 'priority'],
            'status' => ['status', 'review', 'status'],
            'estimate' => ['estimated_minutes', '45', 'estimated_minutes'],
        ];
    }

    #[DataProvider('cells')]
    public function test_a_value_is_changed_in_its_row(string $field, string $value, string $column): void
    {
        $f = $this->teamFixture();

        $this->table($f)->call('saveCell', $f->task->id, $field, $value)->assertHasNoErrors();

        $this->assertEquals($value, $f->task->fresh()->{$column});
    }

    public function test_a_due_date_and_a_person_are_changed_in_their_row(): void
    {
        $f = $this->teamFixture();

        $this->table($f)->call('saveCell', $f->task->id, 'due_date', '2026-12-24')->call('saveCell', $f->task->id, 'assignee', (string) $f->admin->id);

        $this->assertSame('2026-12-24', $f->task->fresh()->due_date->toDateString());
        $this->assertSame($f->admin->id, $f->task->fresh()->assignee_id);

        $this->table($f)->call('saveCell', $f->task->id, 'assignee', '');
        $this->assertNull($f->task->fresh()->assignee_id);
    }

    public function test_a_stranger_cannot_be_given_a_task(): void
    {
        $f = $this->teamFixture();

        $this->table($f)->call('saveCell', $f->task->id, 'assignee', (string) $f->outsider->id)->assertForbidden();

        $this->assertNull($f->task->fresh()->assignee_id);
    }

    public function test_a_refused_change_says_why_and_puts_the_control_back(): void
    {
        $f = $this->teamFixture();
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo']);
        $f->task->blockedBy()->attach($first);

        $table = $this->table($f);
        $table->call('saveCell', $f->task->id, 'status', 'done')->assertHasErrors('status');

        $this->assertSame('todo', $f->task->fresh()->status);
        $this->assertSame(1, $table->get('edits'), 'every row is rekeyed, so its select shows the truth again');
    }

    public function test_a_row_on_another_board_cannot_be_reached_from_this_one(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $theirs = $other->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $this->table($f)->call('saveCell', $theirs->id, 'title', 'Mine now')->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    /** @return array<string, array{string, string}> */
    public static function viewerEdits(): array
    {
        return ['title' => ['title', 'Nope'], 'status' => ['status', 'done'], 'person' => ['assignee', '']];
    }

    #[DataProvider('viewerEdits')]
    public function test_a_viewer_changes_nothing(string $field, string $value): void
    {
        $f = $this->teamFixture();

        $this->table($f, 'viewer')->call('saveCell', $f->task->id, $field, $value)->assertForbidden();

        $this->assertSame('Fixture task', $f->task->fresh()->title);
        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_viewer_sees_text_not_controls(): void
    {
        $f = $this->teamFixture();

        $this->table($f, 'viewer')->assertSee('Fixture task')->assertDontSeeHtml('saveCell')->assertDontSeeHtml('type="checkbox" aria-label="Select');
    }

    public function test_a_person_chooses_the_columns(): void
    {
        $f = $this->teamFixture();

        $table = $this->table($f)->call('toggleColumn', 'estimate')->call('toggleColumn', 'status')->call('toggleColumn', 'banana');

        $this->assertSame(['assignee', 'priority', 'due', 'estimate'], $table->instance()->shown);
    }

    public function test_only_your_own_order_under_a_status_or_lane_can_be_dragged(): void
    {
        $f = $this->teamFixture();

        $this->assertTrue($this->table($f)->instance()->canDrag);
        $this->assertTrue($this->table($f, 'editor', ['group' => 'assignee'])->instance()->canDrag);
        $this->assertFalse($this->table($f, 'editor', ['sort' => 'due'])->instance()->canDrag);
        $this->assertFalse($this->table($f, 'editor', ['group' => 'due'])->instance()->canDrag);
        $this->assertFalse($this->table($f, 'viewer')->instance()->canDrag);
    }

    public function test_a_row_dropped_under_someone_else_is_theirs(): void
    {
        $f = $this->teamFixture();

        $this->table($f, 'editor', ['group' => 'assignee'])->call('place', $f->task->id, 'todo', null, 'todo', (string) $f->admin->id)->assertReturned(['ok' => true]);

        $this->assertSame($f->admin->id, $f->task->fresh()->assignee_id);
    }

    public function test_a_row_dropped_on_another_status_moves_there(): void
    {
        $f = $this->teamFixture();

        $this->table($f)->call('place', $f->task->id, 'review', null, 'todo')->assertReturned(['ok' => true]);

        $this->assertSame('review', $f->task->fresh()->status);
    }

    public function test_many_tasks_change_at_once(): void
    {
        $f = $this->teamFixture();
        $second = $f->list->tasks()->create(['title' => 'Second', 'status' => 'todo']);

        $table = $this->table($f)->call('toggleAll');
        $this->assertCount(2, $table->get('selected'));

        $table->call('bulk', 'priority', 'urgent')->assertSet('selected', [])->assertSee('2 tasks changed.');
        $this->assertSame('urgent', $second->fresh()->priority);

        $table->set('selected', [$second->id])->call('bulk', 'status', 'review')->assertSee('1 task changed.');
        $this->assertSame('review', $second->fresh()->status);
        $this->assertSame('todo', $f->task->fresh()->status);

        $table->set('selected', [$second->id])->call('bulk', 'assignee', (string) $f->admin->id);
        $this->assertSame($f->admin->id, $second->fresh()->assignee_id);

        $table->set('selected', [$second->id])->call('bulk', 'due_date', '2026-12-01');
        $this->assertSame('2026-12-01', $second->fresh()->due_date->toDateString());
    }

    public function test_one_task_that_cannot_change_does_not_stop_the_rest(): void
    {
        $f = $this->teamFixture();
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo']);
        $f->task->blockedBy()->attach($first);

        $this->table($f)->set('selected', [$f->task->id, $first->id])->call('bulk', 'status', 'done')
            ->assertSee('1 task changed.')->assertSee('Fixture task');

        $this->assertSame('done', $first->fresh()->status);
        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_archiving_many_takes_their_subtasks_too(): void
    {
        $f = $this->teamFixture();
        $sub = $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'parent_id' => $f->task->id]);

        $this->table($f)->set('selected', [$f->task->id])->call('bulk', 'archive');

        $this->assertNotNull(Task::withArchived()->find($f->task->id)->archived_at);
        $this->assertNotNull(Task::withArchived()->find($sub->id)->archived_at);
    }

    public function test_a_batch_touches_only_this_board_and_only_what_a_batch_may_change(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $theirs = $other->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $this->table($f)->set('selected', [$theirs->id, $f->task->id])->call('bulk', 'priority', 'urgent');
        $this->assertSame('medium', $theirs->fresh()->priority);

        $this->table($f)->set('selected', [$f->task->id])->call('bulk', 'title', 'All the same')->assertStatus(422);
        $this->assertSame('Fixture task', $f->task->fresh()->title);
    }

    public function test_a_viewer_cannot_run_a_batch(): void
    {
        $f = $this->teamFixture();

        $this->table($f, 'viewer')->set('selected', [$f->task->id])->call('bulk', 'priority', 'urgent')->assertForbidden();

        $this->assertSame('medium', $f->task->fresh()->priority);
    }

    public function test_a_viewer_cannot_archive_by_the_batch(): void
    {
        $f = $this->teamFixture();

        $this->table($f, 'viewer')->set('selected', [$f->task->id])->call('bulk', 'archive')->assertForbidden();

        $this->assertNull($f->task->fresh()->archived_at);
    }

    public function test_setting_the_same_person_again_says_nothing_to_them(): void
    {
        $f = $this->teamFixture();
        Notification::fake();
        $f->task->update(['assignee_id' => $f->admin->id]);

        $this->table($f)->call('saveCell', $f->task->id, 'assignee', (string) $f->admin->id);

        Notification::assertNothingSent();
        $this->assertSame(0, $f->task->activities()->count());
    }

    public function test_a_person_saves_a_view_and_finds_it_again(): void
    {
        $f = $this->teamFixture();

        $table = $this->table($f, 'editor', ['group' => 'priority', 'sort' => 'due', 'priority' => 'high'])->call('saveView', 'My urgent list');

        $this->assertSame(['group' => 'priority', 'sort' => 'due', 'view' => 'list', 'priority' => 'high'], collect(TaskView::sole()->settings)->sortKeys()->pipe(fn ($c) => ['group' => $c['group'], 'sort' => $c['sort'], 'view' => $c['view'], 'priority' => $c['priority']]));
        $table->assertSee('My urgent list')->assertSeeHtml('view=list');
    }

    public function test_a_board_view_is_saved_too(): void
    {
        $f = $this->teamFixture();

        Livewire::withQueryParams(['lanes' => 'assignee'])->actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->call('saveView', 'By person');

        $this->assertSame(['lanes' => 'assignee'], TaskView::sole()->settings);
    }

    public function test_saving_under_the_same_name_replaces_it(): void
    {
        $f = $this->teamFixture();

        $this->table($f)->call('saveView', 'Mine')->call('saveView', 'Mine');

        $this->assertSame(1, TaskView::count());
    }

    public function test_a_view_needs_a_name(): void
    {
        $f = $this->teamFixture();

        $this->table($f)->call('saveView', '   ')->assertHasErrors('viewName');

        $this->assertSame(0, TaskView::count());
    }

    public function test_a_saved_view_is_nobody_elses(): void
    {
        $f = $this->teamFixture();
        $this->table($f)->call('saveView', 'Private view');
        $view = TaskView::sole();

        $this->table($f, 'admin')->assertDontSee('Private view')->call('deleteView', $view->id);
        $this->assertNotNull($view->fresh());

        $this->table($f)->call('deleteView', $view->id);
        $this->assertNull($view->fresh());
    }

    public function test_the_query_count_does_not_grow_with_the_rows(): void
    {
        $f = $this->teamFixture();
        $count = function (int $rows) use ($f): int {
            foreach (range(1, $rows) as $n) {
                $task = $f->list->tasks()->create(['title' => 'Row '.$n, 'status' => 'todo', 'assignee_id' => $f->admin->id]);
                $task->labels()->attach($f->label);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->table($f)->instance()->groups;

            return count(DB::getQueryLog());
        };

        $count(1); // The signed-in person's team is read once, and stays read.
        $this->assertSame($count(3), $count(30));
        DB::disableQueryLog();
    }
}
