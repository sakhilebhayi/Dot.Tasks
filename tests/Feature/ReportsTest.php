<?php

namespace Tests\Feature;

use App\Livewire\Reports;
use App\Models\Task;
use App\Models\TaskField;
use App\Models\TaskList;
use App\Services\InboxLists;
use App\Services\TaskReports;
use App\Services\TaskStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §13: plain measures with their definitions, and no scores of people. */
class ReportsTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function make(object $f, array $values = []): Task
    {
        return $f->list->tasks()->create($values + ['title' => 'Task', 'status' => 'todo']);
    }

    private function finished(object $f, string $made, string $done, array $values = []): Task
    {
        $task = $this->make($f, $values + ['status' => 'done', 'completed_at' => $done]);
        DB::table('tasks')->where('id', $task->id)->update(['created_at' => $made]);

        return $task;
    }

    private function report(object $f, string $from = '2026-10-01', string $to = '2026-11-05', ?int $board = null, ?int $field = null): array
    {
        return app(TaskReports::class)->report($f->team, Carbon::parse($from), Carbon::parse($to), $board, $field);
    }

    public function test_created_and_finished_count_inside_the_period_only(): void
    {
        $f = $this->teamFixture();
        $this->finished($f, '2026-10-10 10:00:00', '2026-10-11 10:00:00');
        $this->finished($f, '2026-09-10 10:00:00', '2026-11-05 08:00:00');
        $this->finished($f, '2026-08-10 10:00:00', '2026-08-11 10:00:00');

        $report = $this->report($f);

        $this->assertSame(2, $report['created'], 'the fixture task and the one made on 10 October');
        $this->assertSame(2, $report['finished'], 'including one finished on the last day of the period');
    }

    public function test_time_to_finish_is_given_as_a_mean_and_a_median(): void
    {
        $f = $this->teamFixture();
        $this->finished($f, '2026-10-10 10:00:00', '2026-10-10 11:00:00');
        $this->finished($f, '2026-10-10 10:00:00', '2026-10-10 12:00:00');
        $this->finished($f, '2026-10-10 10:00:00', '2026-10-11 10:00:00');

        $report = $this->report($f);

        $this->assertSame(540, $report['average_minutes']);
        $this->assertSame(120, $report['median_minutes']);
    }

    public function test_with_nothing_finished_there_is_no_average_rather_than_a_zero(): void
    {
        $f = $this->teamFixture();

        $report = $this->report($f);

        $this->assertNull($report['average_minutes']);
        $this->assertNull($report['median_minutes']);
        $this->assertNull($report['on_time']);
    }

    public function test_overdue_blocked_status_and_priority_describe_open_work_now(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['due_date' => '2026-11-01', 'priority' => 'urgent']);
        $this->make($f, ['title' => 'Stuck', 'status' => 'review'])->blockedBy()->attach($f->task);
        $this->finished($f, '2026-10-10 10:00:00', '2026-10-11 10:00:00', ['due_date' => '2026-10-01', 'priority' => 'urgent']);

        $report = $this->report($f);

        $this->assertSame(1, $report['overdue']);
        $this->assertSame(1, $report['blocked']);
        $this->assertSame(['review' => 1, 'todo' => 2], collect($report['by_status'])->sortKeys()->all());
        $this->assertSame(['medium' => 2, 'urgent' => 1], collect($report['by_priority'])->sortKeys()->all());
    }

    public function test_reopened_counts_each_time_a_finished_task_came_back(): void
    {
        $f = $this->teamFixture();
        $this->actingAs($f->editor);
        $status = app(TaskStatusService::class);
        foreach (['done', 'todo', 'done', 'review'] as $to) {
            $status->move($f->task, $to);
        }

        $this->assertSame(2, $this->report($f)['reopened']);
    }

    public function test_another_teams_reopened_tasks_are_not_counted(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere'])->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);
        $theirs->activities()->create(['kind' => 'reopened', 'summary' => 'Reopened it']);

        $this->assertSame(0, $this->report($f)['reopened']);
    }

    public function test_repeating_work_is_on_time_by_its_due_date(): void
    {
        $f = $this->teamFixture();
        $series = ['recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date'];
        $this->finished($f, '2026-10-01 10:00:00', '2026-10-05 16:00:00', $series + ['due_date' => '2026-10-05']);
        $this->finished($f, '2026-10-01 10:00:00', '2026-10-13 10:00:00', $series + ['due_date' => '2026-10-12']);
        $this->finished($f, '2026-10-01 10:00:00', '2026-10-20 10:00:00', ['due_date' => '2026-10-12']);

        $this->assertSame(['on_time' => 1, 'of' => 2], $this->report($f)['on_time']);
    }

    public function test_estimates_compare_only_tasks_that_have_both(): void
    {
        $f = $this->teamFixture();
        $this->finished($f, '2026-10-01 10:00:00', '2026-10-05 16:00:00', ['estimated_minutes' => 60, 'actual_minutes' => 90]);
        $this->finished($f, '2026-10-01 10:00:00', '2026-10-05 16:00:00', ['estimated_minutes' => 60]);

        $this->assertSame(['tasks' => 1, 'estimated' => 60, 'actual' => 90], $this->report($f)['estimate']);
    }

    public function test_people_come_in_name_order_so_it_cannot_read_as_a_ranking(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['assignee_id' => $f->viewer->id, 'estimated_minutes' => 90, 'due_date' => '2026-11-01']);
        $this->make($f, ['assignee_id' => $f->viewer->id]);
        $this->make($f, ['assignee_id' => $f->admin->id]);

        $people = $this->report($f)['by_person'];

        $this->assertSame(['Admin Person Fixture', 'Unassigned', 'Viewer Person Fixture'], array_column($people, 'name'));
        $this->assertSame(['name' => 'Viewer Person Fixture', 'open' => 2, 'overdue' => 1, 'hours' => 1.5], $people[2]);
    }

    public function test_nobody_is_scored(): void
    {
        $f = $this->teamFixture();

        $report = $this->report($f);

        $this->assertArrayNotHasKey('finished', $report['by_person'][0] ?? []);
    }

    public function test_open_work_splits_by_a_custom_field_such_as_a_department(): void
    {
        $f = $this->teamFixture();
        $field = TaskField::create(['team_id' => $f->team->id, 'name' => 'Department', 'type' => 'text']);
        $f->task->fieldValues()->create(['task_field_id' => $field->id, 'value' => 'Finance']);
        $this->make($f);

        $this->assertSame(['Finance' => 1, 'Not set' => 1], $this->report($f, field: $field->id)['by_field']);
    }

    public function test_a_short_period_is_by_day_and_a_long_one_by_week(): void
    {
        $f = $this->teamFixture();
        $this->finished($f, '2026-10-01 10:00:00', '2026-11-04 16:00:00');

        $days = $this->report($f, '2026-11-01', '2026-11-05')['trend'];
        $this->assertCount(5, $days);
        $this->assertSame(1, $days[3]['finished']);

        $weeks = $this->report($f, '2026-08-01', '2026-11-05')['trend'];
        $this->assertSame('27 Jul', $weeks[0]['label']);
        $this->assertSame(1, end($weeks)['finished']);
    }

    public function test_a_board_narrows_it_and_inboxes_and_other_teams_stay_out(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $other->tasks()->create(['title' => 'Over there', 'status' => 'todo']);
        app(InboxLists::class)->for($f->editor, $f->team)->tasks()->create(['title' => 'Private', 'status' => 'todo']);
        TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere'])->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $this->assertSame(2, $this->report($f)['created']);
        $this->assertSame(1, $this->report($f, board: $other->id)['created']);
    }

    public function test_editors_and_up_can_open_it_and_a_viewer_cannot(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('reports.index'))->assertOk()->assertSee('Average time to finish')->assertSee('not a ranking');
        $this->actingAs($f->viewer)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($f->viewer)->get(route('reports.export'))->assertForbidden();
    }

    public function test_every_measure_shows_what_it_means(): void
    {
        $f = $this->teamFixture();

        foreach (TaskReports::DEFINITIONS as $definition) {
            Livewire::actingAs($f->editor)->test(Reports::class)->assertSee($definition);
        }
    }

    public function test_the_period_comes_from_the_controls_and_nonsense_falls_back(): void
    {
        $f = $this->teamFixture();
        $range = fn (array $query) => array_map(fn ($d) => $d->toDateString(), Livewire::withQueryParams($query)->actingAs($f->editor)->test(Reports::class)->instance()->range);

        $this->assertSame(['2026-10-06', '2026-11-05'], $range([]));
        $this->assertSame(['2026-10-29', '2026-11-05'], $range(['period' => '7']));
        $this->assertSame(['2026-01-01', '2026-02-01'], $range(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-02-01']));
        $this->assertSame(['2026-10-06', '2026-11-05'], $range(['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-02-01']));
        $this->assertSame(['2026-10-06', '2026-11-05'], $range(['period' => 'banana']));
    }

    public function test_the_csv_carries_the_figures_and_their_definitions(): void
    {
        $f = $this->teamFixture();
        $this->finished($f, '2026-10-10 10:00:00', '2026-10-11 10:00:00');

        $response = $this->actingAs($f->editor)->get(route('reports.export', ['period' => '90']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Finished,1,', $csv);
        $this->assertStringContainsString('Tasks finished in this period.', $csv);
    }

    public function test_a_name_cannot_run_as_a_formula_in_a_spreadsheet(): void
    {
        $f = $this->teamFixture();
        $rows = (new \ReflectionMethod(TaskReports::class, 'harmless'))->invoke(app(TaskReports::class), [['=1+1', '@SUM(A1)', 'Fine', 5]]);

        $this->assertSame([["'=1+1", "'@SUM(A1)", 'Fine', 5]], $rows);
    }

    public function test_a_board_of_another_team_is_ignored_in_the_export(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere']);
        $theirs->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $csv = $this->actingAs($f->editor)->get(route('reports.export', ['board' => $theirs->id]))->streamedContent();

        $this->assertStringContainsString('Created,1,', $csv);
    }
}
