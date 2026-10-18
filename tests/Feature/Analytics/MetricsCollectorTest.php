<?php

namespace Tests\Feature\Analytics;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The aggregate numbers one company publishes to Dot.Analytics. Pure queries, always scoped to the company
 * explicitly, in UTC, never counting archived or deleted work.
 */
class MetricsCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00'); // a Thursday, UTC
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(): Team
    {
        $owner = User::factory()->create();

        return Team::forceCreate(['user_id' => $owner->id, 'name' => 'Company '.uniqid(), 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
    }

    private function board(Team $team): TaskList
    {
        return TaskList::create(['team_id' => $team->id, 'owner_id' => $team->user_id, 'name' => 'Board']);
    }

    /** @param array<string, mixed> $attributes */
    private function task(TaskList $list, array $attributes = []): Task
    {
        $task = new Task(['task_list_id' => $list->id, 'title' => 'A task', 'status' => 'todo']);
        $task->forceFill($attributes)->save();

        return $task;
    }

    /** @return array<string, int> */
    private function collect(Team $team): array
    {
        return app(MetricsCollector::class)->collect($team);
    }

    public function test_it_reports_exactly_the_keys_it_can_compute_truthfully(): void
    {
        $this->assertSame(['open', 'overdue', 'created_7d'], array_keys($this->collect($this->company())));
    }

    public function test_completed_7d_is_not_reported_because_the_app_does_not_record_when_a_task_was_completed(): void
    {
        $team = $this->company();
        $this->task($this->board($team), ['status' => 'done']);

        $this->assertArrayNotHasKey('completed_7d', $this->collect($team));
    }

    public function test_a_company_with_no_tasks_reports_real_zeros_as_whole_numbers(): void
    {
        $this->assertSame(['open' => 0, 'overdue' => 0, 'created_7d' => 0], $this->collect($this->company()));
    }

    // ------------------------------------------------------------- open

    public function test_open_counts_every_status_except_done(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        foreach (['todo', 'in_progress', 'review', 'done', 'done'] as $status) {
            $this->task($list, ['status' => $status]);
        }

        $this->assertSame(3, $this->collect($team)['open']);
    }

    public function test_open_leaves_out_archived_and_deleted_tasks_and_tasks_on_archived_boards(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list);
        $this->task($list, ['archived_at' => now()->subDay()]);
        $this->task($list)->delete();
        $archivedBoard = $this->board($team);
        $this->task($archivedBoard);
        $archivedBoard->archive();

        $this->assertSame(1, $this->collect($team)['open']);
    }

    public function test_subtasks_count_as_tasks_like_they_do_on_the_dashboard(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $parent = $this->task($list);
        $this->task($list, ['parent_id' => $parent->id]);

        $this->assertSame(2, $this->collect($team)['open']);
    }

    // ------------------------------------------------------------- overdue

    public function test_overdue_is_open_work_due_before_today(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list, ['due_date' => '2026-11-04']);                          // yesterday: overdue
        $this->task($list, ['due_date' => '2026-10-01', 'status' => 'review']);   // long ago: overdue
        $this->task($list, ['due_date' => '2026-11-05']);                          // today: not yet
        $this->task($list, ['due_date' => '2026-11-06']);                          // tomorrow
        $this->task($list, ['due_date' => null]);                                  // no due date
        $this->task($list, ['due_date' => '2026-10-01', 'status' => 'done']);      // done: never overdue

        $collected = $this->collect($team);

        $this->assertSame(2, $collected['overdue']);
        $this->assertSame(5, $collected['open']);
    }

    public function test_overdue_leaves_out_archived_deleted_and_archived_board_tasks(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list, ['due_date' => '2026-10-01']);
        $this->task($list, ['due_date' => '2026-10-01', 'archived_at' => now()]);
        $this->task($list, ['due_date' => '2026-10-01'])->delete();
        $board = $this->board($team);
        $this->task($board, ['due_date' => '2026-10-01']);
        $board->archive();

        $this->assertSame(1, $this->collect($team)['overdue']);
    }

    public function test_today_is_the_utc_day_even_when_the_server_clock_is_elsewhere(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list, ['due_date' => '2026-11-04']);
        $this->task($list, ['due_date' => '2026-11-03']);

        // 01:30 on 5 November in Johannesburg is still 23:30 on 4 November in UTC.
        $johannesburg = Carbon::parse('2026-11-05 01:30:00', 'Africa/Johannesburg');
        $this->assertSame(1, app(MetricsCollector::class)->collect($team, $johannesburg)['overdue'], 'due on the UTC "today" is not overdue yet');
        Carbon::setTestNow('2026-11-05 00:00:00');
        $this->assertSame(2, $this->collect($team)['overdue']);
    }

    // ------------------------------------------------------------- created_7d

    public function test_created_7d_window_includes_exactly_seven_days_back_and_excludes_anything_older(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list, ['created_at' => now()]);                                      // now
        $this->task($list, ['created_at' => now()->subDays(7)]);                          // exactly 7 days ago: in
        $this->task($list, ['created_at' => now()->subDays(7)->subSecond()]);             // a second older: out
        $this->task($list, ['created_at' => now()->subDays(30)]);

        $this->assertSame(2, $this->collect($team)['created_7d']);
    }

    public function test_created_7d_counts_tasks_in_any_status_but_not_archived_or_deleted_ones(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list, ['status' => 'done', 'created_at' => now()->subDay()]);
        $this->task($list, ['status' => 'todo', 'created_at' => now()->subDay()]);
        $this->task($list, ['archived_at' => now(), 'created_at' => now()->subDay()]);
        $this->task($list, ['created_at' => now()->subDay()])->delete();

        $this->assertSame(2, $this->collect($team)['created_7d']);
    }

    public function test_created_7d_window_moves_with_the_clock(): void
    {
        $team = $this->company();
        $list = $this->board($team);
        $this->task($list, ['created_at' => '2026-11-01 12:00:00']);

        $this->assertSame(1, $this->collect($team)['created_7d']);
        Carbon::setTestNow('2026-11-08 12:00:00');
        $this->assertSame(1, $this->collect($team)['created_7d'], 'exactly 7 days on');
        Carbon::setTestNow('2026-11-08 12:00:01');
        $this->assertSame(0, $this->collect($team)['created_7d']);
    }

    // ------------------------------------------------------------- isolation

    public function test_company_a_numbers_never_include_company_b_rows(): void
    {
        $a = $this->company();
        $b = $this->company();
        $listA = $this->board($a);
        $listB = $this->board($b);
        $this->task($listA, ['due_date' => '2026-10-01', 'created_at' => now()->subDay()]);
        foreach (range(1, 4) as $i) {
            $this->task($listB, ['due_date' => '2026-10-01', 'created_at' => now()->subDay()]);
        }

        $this->assertSame(['open' => 1, 'overdue' => 1, 'created_7d' => 1], $this->collect($a));
        $this->assertSame(['open' => 4, 'overdue' => 4, 'created_7d' => 4], $this->collect($b));
    }

    public function test_the_numbers_are_the_same_whoever_is_signed_in_or_nobody(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->task($this->board($a));
        $this->task($this->board($b));
        $this->task($this->board($b));
        $b->owner->forceFill(['current_team_id' => $b->id])->save();

        $anonymous = $this->collect($a);
        $this->actingAs($b->owner);

        $this->assertSame($anonymous, $this->collect($a));
        $this->assertSame(2, $this->collect($b)['open']);
    }
}
