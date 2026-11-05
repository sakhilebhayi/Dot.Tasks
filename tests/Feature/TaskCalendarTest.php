<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskCalendar;
use App\Livewire\Tasks\TaskTimeline;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §7.3 and §7.4: the same tasks by date, moved by hand. */
class TaskCalendarTest extends TestCase
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

    private function calendar(object $f, string $who = 'editor', array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($f->{$who})->test(TaskCalendar::class, ['taskList' => $f->list]);
    }

    private function timeline(object $f, string $who = 'editor', array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($f->{$who})->test(TaskTimeline::class, ['taskList' => $f->list]);
    }

    /** @return array<string, list<string>> titles by date, days with none left out */
    private function dates($calendar): array
    {
        return collect($calendar->instance()->days)->filter(fn ($d) => $d['tasks']->isNotEmpty())
            ->mapWithKeys(fn ($d) => [$d['date'] => $d['tasks']->pluck('title')->all()])->all();
    }

    public function test_both_views_open_from_the_board(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('task-lists.show', [$f->list, 'view' => 'calendar']))->assertOk()->assertSeeLivewire(TaskCalendar::class);
        $this->actingAs($f->editor)->get(route('task-lists.show', [$f->list, 'view' => 'timeline']))->assertOk()->assertSeeLivewire(TaskTimeline::class);
    }

    public function test_the_month_runs_monday_to_sunday_and_holds_whole_weeks(): void
    {
        $f = $this->teamFixture();

        $days = $this->calendar($f)->instance()->days;

        $this->assertSame('2026-10-26', $days[0]['date']);
        $this->assertSame('2026-12-06', end($days)['date']);
        $this->assertCount(42, $days);
        $this->assertFalse($days[0]['inRange']);
        $this->assertTrue(collect($days)->firstWhere('date', '2026-11-05')['today']);
    }

    public function test_a_week_and_a_day_show_just_that(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-08']);

        $this->assertCount(7, $this->calendar($f, 'editor', ['range' => 'week'])->instance()->days);
        $this->assertSame(['2026-11-08' => ['Fixture task']], $this->dates($this->calendar($f, 'editor', ['range' => 'week'])));

        $day = $this->calendar($f, 'editor', ['range' => 'day', 'date' => '2026-11-08']);
        $this->assertCount(1, $day->instance()->days);
        $this->assertSame(['2026-11-08' => ['Fixture task']], $this->dates($day));
        $this->assertSame([], $this->dates($this->calendar($f, 'editor', ['range' => 'day'])));
    }

    public function test_earlier_and_later_move_by_the_range(): void
    {
        $f = $this->teamFixture();

        $this->assertSame('2026-12-01', $this->calendar($f)->call('go', 1)->get('date'));
        $this->assertSame('2026-11-12', $this->calendar($f, 'editor', ['range' => 'week'])->call('go', 1)->get('date'));
        $this->assertSame('2026-11-04', $this->calendar($f, 'editor', ['range' => 'day'])->call('go', -1)->get('date'));
        $this->assertSame('', $this->calendar($f)->call('go', 1)->call('goToday')->get('date'));
    }

    public function test_the_month_does_not_slip_on_the_31st(): void
    {
        $f = $this->teamFixture();

        $this->assertSame('2027-02-01', $this->calendar($f, 'editor', ['date' => '2027-01-31'])->call('go', 1)->get('date'));
    }

    public function test_a_date_in_the_address_bar_that_means_nothing_falls_back_to_today(): void
    {
        $f = $this->teamFixture();

        foreach (['banana', '2026-13-45', '2026-02-30'] as $date) {
            $this->assertSame('2026-11-05', $this->calendar($f, 'editor', ['date' => $date])->instance()->anchor->toDateString());
        }

        $this->assertSame('month', $this->calendar($f, 'editor', ['range' => 'banana'])->instance()->rangeName);
    }

    public function test_filters_and_status_narrow_what_is_shown(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10', 'assignee_id' => $f->admin->id]);
        $f->list->tasks()->create(['title' => 'Done one', 'status' => 'done', 'due_date' => '2026-11-11']);
        $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'due_date' => '2026-11-12', 'parent_id' => $f->task->id]);

        $this->assertSame(['2026-11-10' => ['Fixture task'], '2026-11-11' => ['Done one']], $this->dates($this->calendar($f)));
        $this->assertSame(['2026-11-11' => ['Done one']], $this->dates($this->calendar($f, 'editor', ['state' => 'done'])));
        $this->assertSame(['2026-11-10' => ['Fixture task']], $this->dates($this->calendar($f, 'editor', ['assignee' => (string) $f->admin->id])));
    }

    public function test_what_has_no_date_waits_at_the_side_and_done_work_does_not(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Finished', 'status' => 'done']);

        $this->assertSame(['Fixture task'], $this->calendar($f)->instance()->undated->pluck('title')->all());
    }

    public function test_a_card_moved_to_another_date_stays_there(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);

        $this->calendar($f)->call('reschedule', $f->task->id, '2026-11-20', '2026-11-10')->assertReturned(['ok' => true]);

        $this->assertSame('2026-11-20', $f->task->fresh()->due_date->toDateString());
        $this->assertSame(1, $f->task->activities()->count());
    }

    public function test_a_card_with_a_start_keeps_its_length(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-08', 'due_date' => '2026-11-10']);

        $this->calendar($f)->call('reschedule', $f->task->id, '2026-11-20', '2026-11-10');

        $this->assertSame('2026-11-18', $f->task->fresh()->start_date->toDateString());
        $this->assertSame('2026-11-20', $f->task->fresh()->due_date->toDateString());
    }

    public function test_a_card_with_no_date_gets_one_and_can_lose_it(): void
    {
        $f = $this->teamFixture();

        $this->calendar($f)->call('reschedule', $f->task->id, '2026-11-20', null)->assertReturned(['ok' => true]);
        $this->assertSame('2026-11-20', $f->task->fresh()->due_date->toDateString());

        $this->calendar($f)->call('reschedule', $f->task->id, null, '2026-11-20')->assertReturned(['ok' => true]);
        $this->assertNull($f->task->fresh()->due_date);
    }

    public function test_a_card_taken_off_the_calendar_loses_its_start_too(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-08', 'due_date' => '2026-11-10']);

        $this->calendar($f)->call('reschedule', $f->task->id, null, '2026-11-10');

        $this->assertNull($f->task->fresh()->start_date);
        $this->assertNull($f->task->fresh()->due_date);
    }

    public function test_finished_work_is_not_late(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-02', 'status' => 'done']);

        $this->assertFalse($this->timeline($f)->instance()->rows->first()['late']);
    }

    public function test_a_card_someone_else_moved_stays_where_they_put_it(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);
        $calendar = $this->calendar($f);
        $f->task->update(['due_date' => '2026-11-12']);

        $calendar->call('reschedule', $f->task->id, '2026-11-20', '2026-11-10')
            ->assertReturned(['ok' => false, 'message' => 'Someone else moved that card, so here is where it is now.']);

        $this->assertSame('2026-11-12', $f->task->fresh()->due_date->toDateString());
    }

    public function test_nonsense_is_refused_and_nothing_changes(): void
    {
        $f = $this->teamFixture();

        $this->calendar($f)->call('reschedule', $f->task->id, 'tomorrowish', null)->assertHasErrors('date');

        $this->assertNull($f->task->fresh()->due_date);
    }

    public function test_a_task_on_another_board_cannot_be_moved_from_here(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $theirs = $other->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $this->calendar($f)->call('reschedule', $theirs->id, '2026-11-20', null)->assertReturned(['ok' => false, 'message' => 'That card is no longer on this board.']);
        $this->timeline($f)->call('setDates', $theirs->id, '2026-11-20', '2026-11-20')->assertReturned(['ok' => false, 'message' => 'That task is no longer on this board.']);

        $this->assertNull($theirs->fresh()->due_date);
    }

    public function test_a_viewer_can_look_and_not_touch(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);

        $this->calendar($f, 'viewer')->assertSee('Fixture task')->assertDontSeeHtml('dayList')
            ->call('reschedule', $f->task->id, '2026-11-20', '2026-11-10')->assertForbidden();
        $this->calendar($f, 'viewer')->call('addOn', '2026-11-20', 'Nope')->assertForbidden();
        $this->timeline($f, 'viewer')->assertDontSeeHtml('data-bar')->call('setDates', $f->task->id, '2026-11-20', '2026-11-20', '2026-11-10', '2026-11-10')->assertForbidden();

        $this->assertSame('2026-11-10', $f->task->fresh()->due_date->toDateString());
        $this->assertSame(1, Task::count());
    }

    public function test_a_task_is_added_on_a_date(): void
    {
        $f = $this->teamFixture();

        $this->calendar($f)->call('addOn', '2026-11-20', '  Pay the rates  ')->assertSee('Pay the rates');

        $task = Task::where('title', 'Pay the rates')->sole();
        $this->assertSame('2026-11-20', $task->due_date->toDateString());
        $this->assertSame($f->editor->id, $task->creator_id);
    }

    public function test_an_empty_title_or_a_bad_date_adds_nothing(): void
    {
        $f = $this->teamFixture();

        $this->calendar($f)->call('addOn', '2026-11-20', '  ')->assertHasErrors('title');
        $this->calendar($f)->call('addOn', 'soon', 'A title')->assertHasErrors('date');

        $this->assertSame(1, Task::count());
    }

    public function test_the_timeline_starts_a_week_back_on_a_monday(): void
    {
        $f = $this->teamFixture();

        $timeline = $this->timeline($f);

        $this->assertSame('2026-10-26', $timeline->instance()->first->toDateString());
        $this->assertSame(10 * 26 + 13, $timeline->instance()->todayLeft);
    }

    public function test_a_bar_runs_from_start_to_due(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-02', 'due_date' => '2026-11-04']);

        $bar = $this->timeline($f)->instance()->rows->first();

        $this->assertSame(7 * 26, $bar['left']);
        $this->assertSame(3 * 26, $bar['width']);
        $this->assertTrue($bar['late']);
    }

    public function test_a_task_with_only_a_due_date_is_one_day_long(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);

        $bar = $this->timeline($f)->instance()->rows->first();

        $this->assertSame(26, $bar['width']);
        $this->assertSame('2026-11-10', $bar['start']);
        $this->assertFalse($bar['late']);
    }

    public function test_a_bar_that_runs_off_either_edge_is_clipped_to_the_window(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-10-01', 'due_date' => '2027-06-01']);

        $bar = $this->timeline($f)->instance()->rows->first();

        $this->assertSame(0, $bar['left']);
        $this->assertSame(63 * 26, $bar['width']);
    }

    public function test_what_falls_outside_the_window_is_left_out_and_what_has_no_date_comes_last(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Long gone', 'status' => 'todo', 'due_date' => '2026-01-01']);
        $f->list->tasks()->create(['title' => 'Far off', 'status' => 'todo', 'due_date' => '2028-01-01']);
        $f->list->tasks()->create(['title' => 'Soon', 'status' => 'todo', 'due_date' => '2026-11-10']);

        $this->assertSame(['Soon', 'Fixture task'], $this->timeline($f)->instance()->rows->map(fn ($r) => $r['task']->title)->all());
    }

    public function test_a_bar_moved_keeps_its_length_and_only_a_due_date_stays_only_a_due_date(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);

        $this->timeline($f)->call('setDates', $f->task->id, '2026-11-12', '2026-11-12', '2026-11-10', '2026-11-10')->assertReturned(['ok' => true]);

        $this->assertNull($f->task->fresh()->start_date);
        $this->assertSame('2026-11-12', $f->task->fresh()->due_date->toDateString());

        $this->timeline($f)->call('setDates', $f->task->id, '2026-11-11', '2026-11-13', '2026-11-12', '2026-11-12');

        $this->assertSame('2026-11-11', $f->task->fresh()->start_date->toDateString());
        $this->assertSame('2026-11-13', $f->task->fresh()->due_date->toDateString());
    }

    public function test_dates_someone_else_changed_stay_as_they_left_them(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);
        $timeline = $this->timeline($f);
        $f->task->update(['due_date' => '2026-11-11']);

        $timeline->call('setDates', $f->task->id, '2026-11-20', '2026-11-20', '2026-11-10', '2026-11-10')
            ->assertReturned(['ok' => false, 'message' => 'Someone else changed those dates, so here is where it is now.']);

        $this->assertSame('2026-11-11', $f->task->fresh()->due_date->toDateString());
    }

    public function test_a_start_after_the_due_date_is_refused_with_a_reason(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);

        $this->timeline($f)->call('setDates', $f->task->id, '2026-11-12', '2026-11-11', '2026-11-10', '2026-11-10')
            ->assertReturned(['ok' => false, 'message' => 'The start date has to be on or before the due date.']);

        $this->assertSame('2026-11-10', $f->task->fresh()->due_date->toDateString());
    }

    public function test_a_task_with_no_dates_is_scheduled_by_a_day_on_its_row(): void
    {
        $f = $this->teamFixture();

        $this->timeline($f)->call('schedule', $f->task->id, 14)->assertReturned(['ok' => true]);

        $this->assertSame('2026-11-09', $f->task->fresh()->due_date->toDateString());
        $this->assertNull($f->task->fresh()->start_date);
    }

    public function test_a_line_joins_each_blocker_to_what_waits_on_it(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-12', 'due_date' => '2026-11-14']);
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo', 'due_date' => '2026-11-10']);
        $f->task->blockedBy()->attach($first);

        $lines = $this->timeline($f)->instance()->lines;

        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['late']);
        $this->assertStringStartsWith('M'.((15 + 1) * 26).' 18', $lines[0]['path']);
    }

    public function test_a_task_set_to_start_before_its_blocker_ends_is_flagged(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-08', 'due_date' => '2026-11-14']);
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo', 'due_date' => '2026-11-10']);
        $f->task->blockedBy()->attach($first, ['type' => 'fs']);

        $this->assertTrue($this->timeline($f)->instance()->lines[0]['late']);

        $first->update(['status' => 'done']);
        $this->assertFalse($this->timeline($f)->instance()->lines[0]['late']);
    }

    public function test_each_kind_of_link_is_drawn_from_the_ends_it_joins(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-12', 'due_date' => '2026-11-14']);
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo', 'start_date' => '2026-11-02', 'due_date' => '2026-11-10']);
        $f->task->blockedBy()->attach($first, ['type' => 'ss']);

        $line = $this->timeline($f)->instance()->lines[0];

        $this->assertStringStartsWith('M'.(7 * 26).' 18', $line['path']);
        $this->assertStringEndsWith('H'.(17 * 26), $line['path']);
        $this->assertFalse($line['late'], 'this task starts after the other, which is all the link asks');

        DB::table('task_dependencies')->update(['type' => 'ff']);
        $line = $this->timeline($f)->instance()->lines[0];
        $this->assertStringEndsWith('H'.(20 * 26), $line['path']);
    }

    public function test_starting_the_day_the_other_task_is_due_is_flagged_too(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-10', 'due_date' => '2026-11-14']);
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo', 'due_date' => '2026-11-10']);
        $f->task->blockedBy()->attach($first, ['type' => 'fs']);

        $this->assertTrue($this->timeline($f)->instance()->lines[0]['late']);
    }

    public function test_a_blocker_out_of_view_draws_no_line(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-14']);
        $f->task->blockedBy()->attach($f->list->tasks()->create(['title' => 'Old', 'status' => 'done', 'due_date' => '2026-01-01']));
        $f->task->blockedBy()->attach($f->list->tasks()->create(['title' => 'No date', 'status' => 'todo']));

        $this->assertSame([], $this->timeline($f)->instance()->lines);
    }

    public function test_the_query_count_does_not_grow_with_the_tasks(): void
    {
        $f = $this->teamFixture();
        $count = function (int $rows, string $component) use ($f): int {
            foreach (range(1, $rows) as $n) {
                $task = $f->list->tasks()->create(['title' => 'Row '.$n, 'status' => 'todo', 'due_date' => '2026-11-10']);
                $task->blockedBy()->attach($f->task);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            Livewire::actingAs($f->editor)->test($component, ['taskList' => $f->list]);

            return count(DB::getQueryLog());
        };

        foreach ([TaskCalendar::class, TaskTimeline::class] as $component) {
            $count(1, $component);
            $this->assertSame($count(3, $component), $count(30, $component), $component);
        }
        DB::disableQueryLog();
    }
}
