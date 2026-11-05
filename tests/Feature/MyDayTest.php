<?php

namespace Tests\Feature;

use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TeamWork;
use App\Models\DayPlan;
use App\Models\Task;
use App\Models\TaskList;
use App\Notifications\TaskAssignedNotification;
use App\Services\InboxLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §7.5 and §7.6: a person's day, and who holds what. */
class MyDayTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 09:00:00'); // A Thursday.
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function day(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(MyDay::class);
    }

    private function mine(object $f, string $title, array $attributes = []): Task
    {
        return $f->list->tasks()->create($attributes + ['title' => $title, 'status' => 'todo', 'assignee_id' => $f->editor->id]);
    }

    /** @return list<string> */
    private function titles($tasks): array
    {
        return collect($tasks)->pluck('title')->all();
    }

    public function test_the_page_opens_and_the_old_list_lives_on_under_due_now(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('tasks.today'))->assertOk()->assertSeeLivewire(MyDay::class)->assertSee('My Day');
        $this->actingAs($f->editor)->get(route('tasks.due'))->assertOk()->assertSee('Due now');
    }

    public function test_work_is_sorted_by_what_it_asks_for(): void
    {
        $f = $this->teamFixture();
        $this->mine($f, 'Late', ['due_date' => '2026-11-02']);
        $this->mine($f, 'Today', ['due_date' => '2026-11-05']);
        $this->mine($f, 'Soon', ['due_date' => '2026-11-12']);
        $this->mine($f, 'Far off', ['due_date' => '2026-11-13']);
        $this->mine($f, 'In review', ['status' => 'review']);
        $this->mine($f, 'Urgent, no date', ['priority' => 'urgent']);
        $this->mine($f, 'Plain');
        $blocked = $this->mine($f, 'Waiting');
        $blocked->blockedBy()->attach($f->task);

        $day = $this->day($f)->instance();

        $this->assertSame(['Late'], $this->titles($day->overdue));
        $this->assertSame(['Today'], $this->titles($day->dueOnDay));
        $this->assertSame(['Soon'], $this->titles($day->upcoming));
        $this->assertEqualsCanonicalizing(['In review', 'Urgent, no date', 'Waiting'], $this->titles($day->attention));
    }

    public function test_only_this_persons_work_counts_and_done_work_leaves(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Theirs', 'status' => 'todo', 'assignee_id' => $f->admin->id, 'due_date' => '2026-11-05']);
        $shared = $f->list->tasks()->create(['title' => 'Shared', 'status' => 'todo', 'due_date' => '2026-11-05']);
        $shared->collaborators()->attach($f->editor);
        app(InboxLists::class)->for($f->editor, $f->team)->tasks()->create(['title' => 'From the Inbox', 'status' => 'todo', 'due_date' => '2026-11-05']);
        $this->mine($f, 'Finished', ['status' => 'done', 'due_date' => '2026-11-05', 'completed_at' => now()]);

        $day = $this->day($f)->instance();

        $this->assertEqualsCanonicalizing(['Shared', 'From the Inbox'], $this->titles($day->dueOnDay));
        $this->assertSame(['Finished'], $this->titles($day->finished));
    }

    public function test_the_week_runs_monday_to_sunday_and_shows_what_is_still_open(): void
    {
        $f = $this->teamFixture();
        $this->mine($f, 'Monday, late', ['due_date' => '2026-11-02']);
        $this->mine($f, 'Sunday', ['due_date' => '2026-11-08']);
        $this->mine($f, 'Next week', ['due_date' => '2026-11-09']);
        $this->mine($f, 'Done Monday', ['due_date' => '2026-11-02', 'status' => 'done']);

        $week = $this->day($f)->instance()->week;

        $this->assertCount(7, $week);
        $this->assertSame('2026-11-02', $week[0]['date']);
        $this->assertTrue($week[0]['past']);
        $this->assertSame(['Monday, late'], $this->titles($week[0]['tasks']));
        $this->assertTrue($week[3]['today']);
        $this->assertFalse($week[3]['past']);
        $this->assertSame(['Sunday'], $this->titles($week[6]['tasks']));
    }

    public function test_picking_a_day_shows_what_is_due_then_and_picking_it_again_comes_back(): void
    {
        $f = $this->teamFixture();
        $this->mine($f, 'Friday', ['due_date' => '2026-11-06']);

        $day = $this->day($f)->call('pick', '2026-11-06')->assertSee('Due Friday');
        $this->assertSame(['Friday'], $this->titles($day->instance()->dueOnDay));

        $day->call('pick', '2026-11-06')->assertSee('Due today');
    }

    public function test_a_plan_keeps_the_order_it_is_given_and_leaves_the_lists(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A', ['due_date' => '2026-11-05']);
        $b = $this->mine($f, 'B', ['due_date' => '2026-11-05']);
        $c = $this->mine($f, 'C', ['due_date' => '2026-11-05']);

        $day = $this->day($f)->call('planIt', $a->id)->call('planIt', $b->id)->call('planIt', $c->id, $a->id);

        $this->assertSame(['C', 'A', 'B'], $this->titles($day->instance()->plan));
        $this->assertSame([], $this->titles($day->instance()->dueOnDay));

        $day->call('planIt', $c->id);
        $this->assertSame(['A', 'B', 'C'], $this->titles($day->instance()->plan));
        $this->assertSame(3, DayPlan::count());
    }

    public function test_taking_a_task_off_the_plan_puts_it_back_in_its_list(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A', ['due_date' => '2026-11-05']);

        $day = $this->day($f)->call('planIt', $a->id)->call('unplan', $a->id);

        $this->assertSame([], $this->titles($day->instance()->plan));
        $this->assertSame(['A'], $this->titles($day->instance()->dueOnDay));
    }

    public function test_a_plan_is_private_and_changes_nothing_on_the_task(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A');
        $before = $a->fresh()->updated_at;

        $this->day($f)->call('planIt', $a->id);

        $this->assertSame([], $this->titles($this->day($f, 'admin')->instance()->plan));
        $this->assertEquals($before, $a->fresh()->updated_at);
        $this->assertSame(0, $a->activities()->count());
    }

    public function test_a_viewer_can_plan_their_day(): void
    {
        $f = $this->teamFixture();
        $task = $f->list->tasks()->create(['title' => 'Read this', 'status' => 'todo', 'assignee_id' => $f->viewer->id]);

        $day = $this->day($f, 'viewer')->call('planIt', $task->id);

        $this->assertSame(['Read this'], $this->titles($day->instance()->plan));
    }

    public function test_a_task_from_another_team_or_someone_elses_inbox_cannot_be_planned(): void
    {
        $f = $this->teamFixture();
        $strange = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere']);
        $theirs = $strange->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);
        $private = app(InboxLists::class)->for($f->admin, $f->team)->tasks()->create(['title' => 'Private', 'status' => 'todo']);

        $this->day($f)->call('planIt', $theirs->id)->assertReturned(['ok' => false, 'message' => 'That task is no longer here.']);
        $this->day($f)->call('planIt', $private->id)->assertReturned(['ok' => false, 'message' => 'That task is no longer here.']);

        $this->assertSame(0, DayPlan::count());
    }

    public function test_a_card_dropped_on_a_day_of_the_rack_takes_that_date(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A', ['due_date' => '2026-11-05']);

        $this->day($f)->call('reschedule', $a->id, '2026-11-07', '2026-11-05')->assertReturned(['ok' => true]);

        $this->assertSame('2026-11-07', $a->fresh()->due_date->toDateString());
    }

    public function test_a_date_someone_else_changed_stays_as_they_left_it(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A', ['due_date' => '2026-11-06']);

        $this->day($f)->call('reschedule', $a->id, '2026-11-07', '2026-11-05')
            ->assertReturned(['ok' => false, 'message' => 'Someone else changed that date, so here is where it is now.']);

        $this->assertSame('2026-11-06', $a->fresh()->due_date->toDateString());
    }

    public function test_a_viewer_cannot_change_a_date_or_tick_a_task(): void
    {
        $f = $this->teamFixture();

        $this->day($f, 'viewer')->call('reschedule', $f->task->id, '2026-11-07', null)->assertForbidden();
        $this->day($f, 'viewer')->call('toggleDone', $f->task->id)->assertForbidden();

        $this->assertNull($f->task->fresh()->due_date);
        $this->assertSame('todo', $f->task->fresh()->status);
    }

    public function test_a_task_is_ticked_off_and_back_on_from_the_plan(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A');

        $day = $this->day($f)->call('planIt', $a->id)->call('toggleDone', $a->id);
        $this->assertSame('done', $a->fresh()->status);
        $day->assertSee('1/1');

        $day->call('toggleDone', $a->id);
        $this->assertSame('todo', $a->fresh()->status);
    }

    public function test_a_blocked_task_cannot_be_ticked_off(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A');
        $a->blockedBy()->attach($f->task);

        $this->day($f)->call('toggleDone', $a->id)->assertHasErrors('status');

        $this->assertSame('todo', $a->fresh()->status);
    }

    public function test_work_left_from_the_last_planned_day_can_be_brought_over(): void
    {
        $f = $this->teamFixture();
        $done = $this->mine($f, 'Done', ['status' => 'done']);
        $left = $this->mine($f, 'Left');
        $other = $this->mine($f, 'Second left');
        foreach ([$done, $left, $other] as $i => $task) {
            DayPlan::create(['user_id' => $f->editor->id, 'task_id' => $task->id, 'day' => '2026-11-03', 'position' => $i + 1]);
        }

        $day = $this->day($f)->assertSee('Bring over 2 left unfinished')->call('carryOver');

        $this->assertSame(['Left', 'Second left'], $this->titles($day->instance()->plan));
        $this->assertSame(0, $day->instance()->leftOver);
    }

    public function test_someone_elses_old_plan_offers_nothing(): void
    {
        $f = $this->teamFixture();
        DayPlan::create(['user_id' => $f->admin->id, 'task_id' => $f->task->id, 'day' => '2026-11-03', 'position' => 1]);

        $this->day($f)->assertDontSee('left unfinished');
    }

    public function test_the_query_count_does_not_grow_with_the_day(): void
    {
        $f = $this->teamFixture();
        $count = function (int $rows) use ($f): int {
            foreach (range(1, $rows) as $n) {
                $task = $this->mine($f, 'Row '.$n, ['due_date' => '2026-11-05']);
                $this->mine($f, 'Planned '.$n);
                DayPlan::create(['user_id' => $f->editor->id, 'task_id' => $task->id, 'day' => '2026-11-05', 'position' => $n]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->day($f);

            return count(DB::getQueryLog());
        };

        $count(1);
        $this->assertSame($count(3), $count(20));
        DB::disableQueryLog();
    }

    public function test_the_team_page_shows_who_holds_what(): void
    {
        $f = $this->teamFixture();
        $this->mine($f, 'Late', ['due_date' => '2026-11-02', 'estimated_minutes' => 90]);
        $blocked = $this->mine($f, 'Waiting', ['estimated_minutes' => 30]);
        $blocked->blockedBy()->attach($f->task);
        $this->mine($f, 'Finished', ['status' => 'done']);

        $this->actingAs($f->viewer)->get(route('tasks.team'))->assertOk()->assertSeeLivewire(TeamWork::class);

        $people = collect(Livewire::actingAs($f->admin)->test(TeamWork::class)->instance()->people);
        $editor = $people->firstWhere('key', (string) $f->editor->id);

        $this->assertSame(2, $editor['open']);
        $this->assertSame(1, $editor['overdue']);
        $this->assertSame(1, $editor['blocked']);
        $this->assertSame(120, $editor['minutes']);
        $this->assertSame(1, $people->firstWhere('key', 'none')['open']);
    }

    public function test_the_filters_narrow_the_cards_not_the_figures(): void
    {
        $f = $this->teamFixture();
        $this->mine($f, 'Late', ['due_date' => '2026-11-02']);
        $this->mine($f, 'Next week', ['due_date' => '2026-11-11']);
        $this->mine($f, 'Far', ['due_date' => '2026-12-11']);

        $people = fn (array $query) => collect(Livewire::withQueryParams($query)->actingAs($f->admin)->test(TeamWork::class)->instance()->people)->firstWhere('key', (string) $f->editor->id);

        $this->assertSame(['Late'], $this->titles($people(['show' => 'overdue'])['tasks']));
        $this->assertSame(3, $people(['show' => 'overdue'])['open']);
        $this->assertSame(['Next week'], $this->titles($people(['show' => 'week'])['tasks']));

        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $this->assertSame(0, $people(['board' => (string) $other->id])['open']);
    }

    public function test_a_card_handed_to_someone_else_is_theirs_and_they_hear_about_it(): void
    {
        $f = $this->teamFixture();
        Notification::fake();
        $a = $this->mine($f, 'A');

        Livewire::actingAs($f->admin)->test(TeamWork::class)
            ->call('reassign', $a->id, (string) $f->owner->id, (string) $f->editor->id)->assertReturned(['ok' => true]);

        $this->assertSame($f->owner->id, $a->fresh()->assignee_id);
        Notification::assertSentTo($f->owner, TaskAssignedNotification::class);

        Livewire::actingAs($f->admin)->test(TeamWork::class)->call('reassign', $a->id, 'none', (string) $f->owner->id);
        $this->assertNull($a->fresh()->assignee_id);
    }

    public function test_a_card_cannot_go_to_a_stranger_or_be_taken_by_a_viewer_or_from_under_someone(): void
    {
        $f = $this->teamFixture();
        $a = $this->mine($f, 'A');

        Livewire::actingAs($f->admin)->test(TeamWork::class)->call('reassign', $a->id, (string) $f->outsider->id, (string) $f->editor->id)
            ->assertReturned(['ok' => false, 'message' => 'That person is not on this team.']);
        Livewire::actingAs($f->admin)->test(TeamWork::class)->call('reassign', $a->id, 'none', (string) $f->owner->id)
            ->assertReturned(['ok' => false, 'message' => 'Someone else moved that card, so here is where it is now.']);
        Livewire::actingAs($f->viewer)->test(TeamWork::class)->call('reassign', $a->id, 'none', (string) $f->editor->id)->assertForbidden();

        $this->assertSame($f->editor->id, $a->fresh()->assignee_id);
    }

    public function test_a_card_moves_to_another_board_with_its_subtasks(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $other->tasks()->create(['title' => 'There already', 'status' => 'todo', 'sort_order' => 5]);
        $sub = $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'parent_id' => $f->task->id]);
        $deep = $f->list->tasks()->create(['title' => 'Deep', 'status' => 'todo', 'parent_id' => $sub->id]);

        Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->call('moveToBoard', $f->task->id, $other->id)->assertReturned(['ok' => true]);

        $this->assertSame($other->id, $f->task->fresh()->task_list_id);
        $this->assertSame(6, $f->task->fresh()->sort_order);
        $this->assertSame($other->id, $sub->fresh()->task_list_id);
        $this->assertSame($other->id, $deep->fresh()->task_list_id);
        $this->assertSame(1, $f->task->activities()->where('kind', 'moved')->count());
    }

    public function test_a_card_stays_inside_the_team_and_out_of_other_peoples_inboxes(): void
    {
        $f = $this->teamFixture();
        $strange = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere']);
        $private = app(InboxLists::class)->for($f->admin, $f->team);
        $board = Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list]);

        $board->call('moveToBoard', $f->task->id, $strange->id)->assertReturned(['ok' => false, 'message' => 'That card or board is no longer here.']);
        $board->call('moveToBoard', $f->task->id, $private->id)->assertReturned(['ok' => false, 'message' => 'You cannot add tasks to that board.']);

        $this->assertSame($f->list->id, $f->task->fresh()->task_list_id);
    }

    public function test_a_viewer_cannot_move_a_card_to_another_board(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);

        Livewire::actingAs($f->viewer)->test(TaskBoard::class, ['taskList' => $f->list])->call('moveToBoard', $f->task->id, $other->id)->assertForbidden();
    }

    public function test_a_viewer_cannot_lift_a_task_into_their_own_inbox(): void
    {
        $f = $this->teamFixture();
        $inbox = app(InboxLists::class)->for($f->viewer, $f->team);

        Livewire::actingAs($f->viewer)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('moveToBoard', $inbox->id)->assertForbidden();

        $this->assertSame($f->list->id, $f->task->fresh()->task_list_id);
    }

    public function test_the_panel_offers_only_where_this_person_can_put_it(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other board']);
        $private = app(InboxLists::class)->for($f->admin, $f->team);

        $panel = fn () => Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->assertSee('Other board');
        $panel()->call('moveToBoard', $private->id)->assertForbidden();
        $this->assertSame($f->list->id, $f->task->fresh()->task_list_id);

        $panel()->call('moveToBoard', $other->id);
        $this->assertSame($other->id, $f->task->fresh()->task_list_id);
    }
}
