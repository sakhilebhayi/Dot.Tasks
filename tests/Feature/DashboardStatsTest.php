<?php

namespace Tests\Feature;

use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Services\DashboardStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function add(TaskList $list, string $status, ?string $due = null, array $attrs = []): void
    {
        $list->tasks()->create($attrs + ['title' => "A {$status} task", 'status' => $status, 'due_date' => $due]);
    }

    public function test_an_empty_team_has_zeroes_not_nulls(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->assertSame([
            'total' => 0, 'todo' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0,
            'due_today' => 0, 'overdue' => 0, 'completed_week' => 0,
        ], app(DashboardStats::class)->forTeam($owner->currentTeam));
    }

    public function test_it_counts_by_status_across_every_board_of_the_team(): void
    {
        $f = $this->teamFixture(); // brings one todo task
        $second = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Second board']);
        $this->add($f->list, 'todo');
        $this->add($second, 'in_progress');
        $this->add($second, 'in_progress');
        $this->add($second, 'review');
        $this->add($second, 'done');

        $stats = app(DashboardStats::class)->forTeam($f->team);

        $this->assertSame(6, $stats['total']);
        $this->assertSame(2, $stats['todo']);
        $this->assertSame(2, $stats['in_progress']);
        $this->assertSame(1, $stats['review']);
        $this->assertSame(1, $stats['done']);
    }

    public function test_due_today_and_overdue_count_only_work_that_is_not_done(): void
    {
        Carbon::setTestNow('2026-10-14 10:30:00');
        $f = $this->teamFixture();
        $f->task->delete();
        $this->add($f->list, 'todo', '2026-10-14');
        $this->add($f->list, 'review', '2026-10-14');
        $this->add($f->list, 'done', '2026-10-14');
        $this->add($f->list, 'todo', '2026-10-13');
        $this->add($f->list, 'in_progress', '2026-10-01');
        $this->add($f->list, 'done', '2026-10-01');
        $this->add($f->list, 'todo', '2026-10-15');
        $this->add($f->list, 'todo', null);

        $stats = app(DashboardStats::class)->forTeam($f->team);

        $this->assertSame(2, $stats['due_today']);
        $this->assertSame(2, $stats['overdue']);
    }

    public function test_completed_this_week_counts_done_tasks_touched_since_monday(): void
    {
        Carbon::setTestNow('2026-10-14 10:30:00'); // a Wednesday
        $f = $this->teamFixture();
        $f->task->delete();

        $this->add($f->list, 'done', null, ['title' => 'Done this week']);
        $this->add($f->list, 'todo', null, ['title' => 'Open this week']);

        Carbon::setTestNow('2026-10-09 17:00:00'); // the Friday before
        $this->add($f->list, 'done', null, ['title' => 'Done last week']);

        Carbon::setTestNow('2026-10-14 10:30:00');
        $this->assertSame(1, app(DashboardStats::class)->forTeam($f->team)['completed_week']);
    }

    public function test_it_never_counts_another_teams_tasks(): void
    {
        $f = $this->teamFixture();
        $g = $this->teamFixture();
        $this->add($g->list, 'done');
        $this->add($g->list, 'todo', now()->subDay()->toDateString());

        $stats = app(DashboardStats::class)->forTeam($f->team);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(0, $stats['overdue']);
        $this->assertSame(0, $stats['done']);
    }

    public function test_archived_and_deleted_tasks_and_archived_boards_are_not_counted(): void
    {
        $f = $this->teamFixture();
        $gone = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Gone board']);
        $this->add($gone, 'todo');
        $gone->archive();
        $archived = $f->list->tasks()->create(['title' => 'Archived', 'status' => 'todo']);
        $archived->archive();
        $f->list->tasks()->create(['title' => 'Deleted', 'status' => 'todo'])->delete();

        $this->assertSame(1, app(DashboardStats::class)->forTeam($f->team)['total']);
    }

    public function test_the_whole_set_of_counts_costs_one_query(): void
    {
        $f = $this->teamFixture();
        $this->add($f->list, 'done');

        DB::enableQueryLog();
        app(DashboardStats::class)->forTeam($f->team);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('"team_id" = ?', $queries[0]['query'], 'scoped to the team through Task::forTeam');
        $this->assertStringContainsString('SUM(CASE', $queries[0]['query']);
    }

    public function test_the_dashboard_route_shows_the_service_numbers(): void
    {
        $f = $this->teamFixture();
        $this->add($f->list, 'done');
        $this->add($f->list, 'in_progress');

        $this->actingAs($f->editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('taskCounts', fn (array $counts) => $counts === app(DashboardStats::class)->forTeam(Team::find($f->team->id))
                && $counts['total'] === 3 && $counts['done'] === 1 && $counts['in_progress'] === 1);
    }
}
