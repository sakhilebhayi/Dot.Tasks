<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\DashboardLayout;
use App\Models\DayPlan;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskEscalationProposal;
use App\Models\TaskList;
use App\Notifications\MentionedInCommentNotification;
use App\Services\DashboardFigures;
use App\Services\InboxLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §12: real records, for me, my team and the business, arranged as each person likes. */
class DashboardTest extends TestCase
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

    private function make(object $f, array $values = []): Task
    {
        return $f->list->tasks()->create($values + ['title' => 'Task', 'status' => 'todo']);
    }

    private function dashboard(object $f, string $who = 'editor', array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($f->{$who})->test(Dashboard::class);
    }

    /** @return list<string> */
    private function titles($tasks): array
    {
        return collect($tasks)->pluck('title')->all();
    }

    public function test_my_dashboard_sorts_my_own_work(): void
    {
        $f = $this->teamFixture();
        $me = ['assignee_id' => $f->editor->id];
        $this->make($f, $me + ['title' => 'Late', 'due_date' => '2026-11-01']);
        $this->make($f, $me + ['title' => 'Soon', 'due_date' => '2026-11-07']);
        $this->make($f, $me + ['title' => 'Later', 'due_date' => '2026-11-15', 'priority' => 'urgent']);
        $this->make($f, $me + ['title' => 'Far off', 'due_date' => '2026-12-30']);
        $this->make($f, $me + ['title' => 'Done', 'status' => 'done', 'completed_at' => now()]);
        $this->make($f, ['title' => 'Someone else', 'assignee_id' => $f->admin->id, 'due_date' => '2026-11-01']);
        $this->make($f, $me + ['title' => 'Stuck'])->blockedBy()->attach($f->task);

        $data = app(DashboardFigures::class)->personal($f->editor);

        $this->assertSame(['Late'], $this->titles($data['overdue']));
        $this->assertSame(['Soon'], $this->titles($data['due_soon']));
        $this->assertSame(['Later'], $this->titles($data['upcoming']));
        $this->assertSame(['Later'], $this->titles($data['high']));
        $this->assertSame(['Stuck'], $this->titles($data['blocked']));
        $this->assertSame(['Done'], $this->titles($data['recent']));
        $this->assertSame(5, $data['mine']);
    }

    public function test_my_day_is_counted_as_planned(): void
    {
        $f = $this->teamFixture();
        $done = $this->make($f, ['status' => 'done']);
        $open = $this->make($f);
        foreach ([$done, $open] as $task) {
            DayPlan::create(['user_id' => $f->editor->id, 'task_id' => $task->id, 'day' => '2026-11-05']);
        }

        $this->assertSame(['total' => 2, 'done' => 1], app(DashboardFigures::class)->personal($f->editor)['plan']);
    }

    public function test_what_waits_for_my_response_is_approvals_and_mentions(): void
    {
        $f = $this->teamFixture();
        $f->task->approvals()->create(['approver_id' => $f->editor->id, 'requested_by' => $f->admin->id]);
        $mentioned = $this->make($f, ['title' => 'Mentioned']);
        $f->editor->notify(new MentionedInCommentNotification($mentioned, TaskComment::create(['task_id' => $mentioned->id, 'user_id' => $f->admin->id, 'body' => 'Look'])));

        $this->assertEqualsCanonicalizing(['Fixture task', 'Mentioned'], $this->titles(app(DashboardFigures::class)->personal($f->editor)['awaiting']));
    }

    public function test_someone_elses_plan_and_approvals_are_not_mine(): void
    {
        $f = $this->teamFixture();
        DayPlan::create(['user_id' => $f->admin->id, 'task_id' => $f->task->id, 'day' => '2026-11-05']);
        $f->task->approvals()->create(['approver_id' => $f->admin->id, 'requested_by' => $f->owner->id]);

        $data = app(DashboardFigures::class)->personal($f->editor);

        $this->assertSame(0, $data['plan']['total']);
        $this->assertSame([], $this->titles($data['awaiting']));
    }

    public function test_a_board_from_another_team_filters_nothing(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere']);

        $this->dashboard($f, 'editor', ['view' => 'team', 'board' => (string) $theirs->id])->assertSee('Fixture task');
    }

    public function test_the_team_view_counts_real_records(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['assignee_id' => $f->admin->id, 'due_date' => '2026-11-01']);
        $this->make($f, ['assignee_id' => $f->admin->id]);
        $this->make($f, ['status' => 'done', 'completed_at' => '2026-11-03 10:00:00']);
        $this->make($f, ['status' => 'review']);

        $data = app(DashboardFigures::class)->team($f->team);

        $load = collect($data['workload'])->keyBy('name');
        $this->assertSame(['name' => 'Unassigned', 'open' => 2, 'overdue' => 0], $load['Unassigned']);
        $this->assertSame(['name' => 'Admin Person Fixture', 'open' => 2, 'overdue' => 1], $load['Admin Person Fixture']);
        $this->assertSame(['todo' => 3, 'in_progress' => 0, 'review' => 1, 'done' => 1], $data['status']);
        $this->assertCount(12, $data['trend']);
        $this->assertSame(1, end($data['trend'])['finished']);
        $this->assertSame('2 Nov', end($data['trend'])['label']);
    }

    public function test_a_week_is_monday_to_sunday_and_old_work_falls_off(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['status' => 'done', 'completed_at' => '2026-11-01 23:00:00']);
        $this->make($f, ['status' => 'done', 'completed_at' => '2026-11-02 01:00:00']);
        $this->make($f, ['status' => 'done', 'completed_at' => '2026-01-01 10:00:00']);

        $trend = app(DashboardFigures::class)->team($f->team)['trend'];

        $this->assertSame(1, $trend[11]['finished']);
        $this->assertSame(1, $trend[10]['finished']);
        $this->assertSame(2, array_sum(array_column($trend, 'finished')));
    }

    public function test_what_needs_a_manager_is_long_overdue_or_up_for_escalation(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['title' => 'Long overdue', 'due_date' => '2026-10-20']);
        $this->make($f, ['title' => 'Just late', 'due_date' => '2026-11-03']);
        $escalated = $this->make($f, ['title' => 'Escalated']);
        TaskEscalationProposal::create(['task_id' => $escalated->id, 'status' => 'pending', 'reason' => 'x']);

        $this->assertEqualsCanonicalizing(['Long overdue', 'Escalated'], $this->titles(app(DashboardFigures::class)->team($f->team)['attention']));
    }

    public function test_the_team_view_narrows_to_a_board(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $other->tasks()->create(['title' => 'Over there', 'status' => 'todo']);

        $this->assertSame(1, array_sum(app(DashboardFigures::class)->team($f->team, $other->id)['status']));
        $this->dashboard($f, 'editor', ['view' => 'team', 'board' => (string) $other->id])->assertSee('Over there')->assertDontSee('Fixture task');
    }

    public function test_private_inboxes_stay_out_of_team_and_business_figures(): void
    {
        $f = $this->teamFixture();
        app(InboxLists::class)->for($f->editor, $f->team)->tasks()->create(['title' => 'Private', 'status' => 'todo', 'due_date' => '2026-11-01']);

        $data = app(DashboardFigures::class)->team($f->team);

        $this->assertSame(1, array_sum($data['status']));
        $this->assertSame([], $this->titles($data['overdue']));
        $this->assertSame(1, array_sum(app(DashboardFigures::class)->business($f->team)['status']));
    }

    public function test_the_figures_stay_inside_the_team(): void
    {
        $f = $this->teamFixture();
        TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere'])
            ->tasks()->create(['title' => 'Theirs', 'status' => 'todo']);

        $this->assertSame(1, array_sum(app(DashboardFigures::class)->team($f->team)['status']));
        $this->assertSame(['Fixture board'], array_column(app(DashboardFigures::class)->business($f->team)['boards'], 'name'));
    }

    public function test_the_business_view_shows_boards_and_no_names(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['assignee_id' => $f->admin->id, 'due_date' => '2026-11-01', 'title' => 'Named task']);
        $this->make($f, ['status' => 'done', 'completed_at' => '2026-11-01 10:00:00']);
        $this->make($f, ['status' => 'done', 'completed_at' => '2026-09-01 10:00:00']);

        $this->assertSame(['id' => $f->list->id, 'name' => 'Fixture board', 'open' => 2, 'overdue' => 1, 'blocked' => 0, 'finished' => 1], app(DashboardFigures::class)->business($f->team)['boards'][0]);

        $this->dashboard($f, 'admin', ['view' => 'business'])->assertSee('Fixture board')->assertDontSee('Admin Person Fixture');
    }

    public function test_only_admins_and_the_owner_get_the_business_view(): void
    {
        $f = $this->teamFixture();

        $this->assertSame(['personal', 'team'], $this->dashboard($f)->instance()->views);
        $this->assertSame('personal', $this->dashboard($f, 'viewer', ['view' => 'business'])->get('view'));
        $this->assertContains('business', $this->dashboard($f, 'admin')->instance()->views);
    }

    public function test_a_view_from_the_address_bar_that_means_nothing_falls_back(): void
    {
        $f = $this->teamFixture();

        $this->assertSame('personal', $this->dashboard($f, 'editor', ['view' => 'banana'])->get('view'));
    }

    public function test_the_page_opens_for_every_role_with_nothing_in_it(): void
    {
        $f = $this->teamFixture();
        $f->task->delete();

        foreach (['viewer', 'editor', 'admin', 'owner'] as $who) {
            $this->actingAs($f->{$who})->get(route('dashboard'))->assertOk()->assertSeeLivewire(Dashboard::class);
        }
    }

    public function test_a_person_rearranges_hides_and_shows_their_own_dashboard(): void
    {
        $f = $this->teamFixture();

        $dashboard = $this->dashboard($f)->call('placeWidget', 8, 1)->call('toggleWidget', 2);

        $this->assertSame(8, $dashboard->instance()->widgets[0]['id']);
        $this->assertTrue(collect($dashboard->instance()->widgets)->firstWhere('id', 2)['hidden']);
        $dashboard->assertDontSee('Overdue</h2>', false);

        $again = $this->dashboard($f);
        $this->assertSame(8, $again->instance()->widgets[0]['id'], 'it comes back as they left it');

        $this->assertSame(1, $this->dashboard($f, 'admin')->instance()->widgets[0]['id'], 'and it is theirs alone');

        $shown = $this->dashboard($f)->call('toggleWidget', 2);
        $this->assertFalse(collect($shown->instance()->widgets)->firstWhere('id', 2)['hidden']);
    }

    public function test_reset_goes_back_and_a_widget_from_another_view_is_refused(): void
    {
        $f = $this->teamFixture();

        $dashboard = $this->dashboard($f)->call('placeWidget', 8, 1)->call('resetLayout');
        $this->assertSame(1, $dashboard->instance()->widgets[0]['id']);

        $this->dashboard($f)->call('placeWidget', 21)->assertNotFound();
        $this->dashboard($f)->call('toggleWidget', 999)->assertNotFound();
    }

    public function test_an_admin_sets_what_the_team_starts_from_and_a_person_can_still_differ(): void
    {
        $f = $this->teamFixture();

        $this->dashboard($f, 'admin')->call('placeWidget', 4, 1)->call('shareLayout');
        $this->assertSame(4, $this->dashboard($f)->instance()->widgets[0]['id']);

        $this->dashboard($f)->call('placeWidget', 8, 4);
        $this->assertSame(8, $this->dashboard($f)->instance()->widgets[0]['id']);
        $this->assertSame(4, $this->dashboard($f, 'viewer')->instance()->widgets[0]['id']);
    }

    public function test_an_editor_cannot_set_the_teams_layout(): void
    {
        $f = $this->teamFixture();

        $this->dashboard($f)->call('shareLayout')->assertForbidden();

        $this->assertSame(0, DashboardLayout::count());
    }

    public function test_a_layout_stays_with_its_team(): void
    {
        $f = $this->teamFixture();
        DashboardLayout::create(['team_id' => $f->outsider->currentTeam->id, 'user_id' => null, 'view' => 'personal', 'layout' => ['order' => [8], 'hidden' => []]]);

        $this->assertSame(1, $this->dashboard($f)->instance()->widgets[0]['id']);
    }
}
