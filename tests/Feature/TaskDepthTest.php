<?php

namespace Tests\Feature;

use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskRepeat;
use App\Models\Task;
use App\Services\RecurrenceEditor;
use App\Services\TaskRecurrenceService;
use App\Services\TaskStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §8: dependencies that say what they wait for, and series that know when they end. */
class TaskDepthTest extends TestCase
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

    private function board(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskBoard::class, ['taskList' => $f->list]);
    }

    /** @return array{0: Task, 1: Task} */
    private function linked(object $f, string $type): array
    {
        $blocker = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo']);
        $f->task->blockedBy()->attach($blocker, ['type' => $type]);

        return [$f->task->fresh(), $blocker];
    }

    /** @return array<string, array{string, string, bool, string}> */
    public static function rules(): array
    {
        return [
            'finish before finish: can start' => ['ff', 'todo', true, 'in_progress'],
            'finish before finish: cannot finish' => ['ff', 'in_progress', false, 'done'],
            'finish before start: cannot start' => ['fs', 'in_progress', false, 'in_progress'],
            'finish before start: other done, can start' => ['fs', 'done', true, 'in_progress'],
            'start before start: other not started, cannot start' => ['ss', 'todo', false, 'in_progress'],
            'start before start: other started, can start' => ['ss', 'in_progress', true, 'in_progress'],
            'start before start: cannot finish before it starts' => ['ss', 'todo', false, 'done'],
            'start before finish: can start' => ['sf', 'todo', true, 'in_progress'],
            'start before finish: other not started, cannot finish' => ['sf', 'todo', false, 'done'],
            'start before finish: other started, can finish' => ['sf', 'in_progress', true, 'done'],
        ];
    }

    #[DataProvider('rules')]
    public function test_each_kind_of_link_holds_back_the_right_move(string $type, string $otherStatus, bool $allowed, string $target): void
    {
        $f = $this->teamFixture();
        [$task, $blocker] = $this->linked($f, $type);
        $blocker->update(['status' => $otherStatus]);

        $board = $this->board($f)->call('place', $task->id, $target);

        $this->assertSame($allowed ? $target : 'todo', $task->fresh()->status);
        $allowed ? $board->assertHasNoErrors() : $board->assertHasErrors('status');
    }

    public function test_work_already_started_is_not_stopped_by_a_new_start_link(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['status' => 'in_progress']);
        $this->linked($f, 'fs');

        $this->board($f)->call('place', $f->task->id, 'review')->assertHasNoErrors();

        $this->assertSame('review', $f->task->fresh()->status);
    }

    public function test_the_reason_names_what_is_being_waited_for(): void
    {
        $f = $this->teamFixture();
        [$task] = $this->linked($f, 'fs');

        $this->board($f)->call('place', $task->id, 'in_progress')
            ->assertReturned(['ok' => false, 'message' => 'This cannot start yet: "First" has to finish before this starts.']);
    }

    public function test_a_card_says_blocked_only_while_its_link_still_holds(): void
    {
        $f = $this->teamFixture();
        [$task, $blocker] = $this->linked($f, 'ss');

        $this->assertTrue($task->isBlocked());

        $blocker->update(['status' => 'in_progress']);
        $this->assertFalse($task->fresh()->isBlocked());
    }

    public function test_my_day_reads_blocked_the_same_way(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id]);
        [, $blocker] = $this->linked($f, 'ss');

        $day = fn () => Livewire::actingAs($f->editor)->test(MyDay::class)->instance()->attention->pluck('title')->all();

        $this->assertSame(['Fixture task'], $day());
        $blocker->update(['status' => 'in_progress']);
        $this->assertSame([], $day());
    }

    public function test_a_link_is_added_with_its_kind_and_explained_in_the_panel(): void
    {
        $f = $this->teamFixture();
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo']);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)
            ->set('blockerType', 'sf')->call('addBlocker', $first->id)
            ->assertSee('has to start before this finishes');

        $this->assertSame('sf', $f->task->blockedBy()->first()->pivot->type);
    }

    public function test_a_kind_of_link_that_does_not_exist_is_refused(): void
    {
        $f = $this->teamFixture();
        $first = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo']);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)
            ->set('blockerType', 'zz')->call('addBlocker', $first->id)->assertHasErrors('blocker');

        $this->assertSame(0, $f->task->blockedBy()->count());
    }

    public function test_a_loop_is_still_refused_whatever_the_kind(): void
    {
        $f = $this->teamFixture();
        [$task, $blocker] = $this->linked($f, 'fs');

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $blocker->id)
            ->set('blockerType', 'ss')->call('addBlocker', $task->id)->assertHasErrors('blocker');
    }

    private function series(object $f, array $rule = [], string $due = '2026-11-05'): Task
    {
        $f->task->update(['due_date' => $due, 'recurrence_type' => 'daily', 'recurrence_anchor' => 'due_date', 'recurrence_interval' => 1]);
        $this->actingAs($f->editor);
        if ($rule !== []) {
            app(RecurrenceEditor::class)->set($f->task->fresh(), $rule + ['kind' => 'daily', 'every' => 1, 'basis' => 'due_date', 'missed' => 'keep']);
        }

        return $f->task->fresh();
    }

    private function finish(Task $task): ?Task
    {
        app(TaskStatusService::class)->move($task, 'done');

        return Task::where('recurrence_source_id', $task->id)->first();
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function dates(): array
    {
        return [
            'every 2 days' => [['kind' => 'daily', 'every' => 2], '2026-11-05', '2026-11-07'],
            'every week' => [['kind' => 'weekly'], '2026-11-05', '2026-11-12'],
            'every 3 weeks' => [['kind' => 'weekly', 'every' => 3], '2026-11-05', '2026-11-26'],
            'next chosen weekday this week' => [['kind' => 'weekly', 'days' => [1, 4, 6]], '2026-11-05', '2026-11-07'],
            'first chosen weekday next week' => [['kind' => 'weekly', 'days' => [1, 4]], '2026-11-05', '2026-11-09'],
            'first chosen weekday in two weeks' => [['kind' => 'weekly', 'every' => 2, 'days' => [2]], '2026-11-05', '2026-11-17'],
            'every month' => [['kind' => 'monthly'], '2026-11-05', '2026-12-05'],
            'the 31st in a short month' => [['kind' => 'monthly'], '2027-01-31', '2027-02-28'],
            'every year' => [['kind' => 'yearly'], '2026-11-05', '2027-11-05'],
            'every 2 years' => [['kind' => 'yearly', 'every' => 2], '2026-11-05', '2028-11-05'],
            'a leap day in an ordinary year' => [['kind' => 'yearly'], '2028-02-29', '2029-02-28'],
        ];
    }

    #[DataProvider('dates')]
    public function test_the_next_date_follows_the_rule(array $rule, string $due, string $next): void
    {
        $f = $this->teamFixture();

        $occurrence = $this->finish($this->series($f, $rule, $due));

        $this->assertSame($next, $occurrence->due_date->toDateString());
    }

    public function test_the_31st_comes_back_after_a_short_month(): void
    {
        $f = $this->teamFixture();

        $february = $this->finish($this->series($f, ['kind' => 'monthly'], '2027-01-31'));
        $march = $this->finish($february);

        $this->assertSame('2027-03-31', $march->due_date->toDateString());
    }

    public function test_a_series_stops_on_its_end_date(): void
    {
        $f = $this->teamFixture();

        $last = $this->finish($this->series($f, ['until' => '2026-11-06']));

        $this->assertNotNull($last);
        $this->assertNull($this->finish($last));
    }

    public function test_a_series_stops_after_its_number_of_times(): void
    {
        $f = $this->teamFixture();

        $second = $this->finish($this->series($f, ['limit' => 2]));

        $this->assertSame(2, $second->recurrence_count);
        $this->assertNull($this->finish($second));
    }

    public function test_a_series_that_fell_behind_picks_up_from_today(): void
    {
        $f = $this->teamFixture();

        $next = $this->finish($this->series($f, ['kind' => 'weekly', 'missed' => 'skip'], '2026-09-03'));

        $this->assertSame('2026-11-05', $next->due_date->toDateString());
    }

    public function test_or_keeps_every_occurrence_when_told_to(): void
    {
        $f = $this->teamFixture();

        $next = $this->finish($this->series($f, ['kind' => 'weekly', 'missed' => 'keep'], '2026-09-03'));

        $this->assertSame('2026-09-10', $next->due_date->toDateString());
    }

    public function test_counting_from_the_day_it_was_finished(): void
    {
        $f = $this->teamFixture();

        $next = $this->finish($this->series($f, ['kind' => 'weekly', 'basis' => 'completion'], '2026-09-03'));

        $this->assertSame('2026-11-12', $next->due_date->toDateString());
    }

    public function test_an_edit_to_one_occurrence_stays_with_it(): void
    {
        $f = $this->teamFixture();
        $second = $this->finish($this->series($f));

        $second->update(['title' => 'Just this once', 'priority' => 'urgent']);
        $third = $this->finish($second);

        $this->assertSame('Fixture task', $third->title);
        $this->assertSame('medium', $third->priority);
    }

    public function test_a_label_changed_on_one_occurrence_stays_with_it(): void
    {
        $f = $this->teamFixture();
        $f->task->labels()->attach($f->label);
        $second = $this->finish($this->series($f));
        $second->labels()->detach();

        $this->assertSame(1, $this->finish($second)->labels()->count());
    }

    public function test_a_finished_occurrence_keeps_the_rule_it_ran_under(): void
    {
        $f = $this->teamFixture();
        $second = $this->finish($this->series($f));
        $third = $this->finish($second);

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $third])->set('kind', 'weekly')->set('every', 1)->call('save');

        $this->assertSame('daily', $second->fresh()->recurrence_type);
    }

    public function test_weekdays_only_belong_to_a_weekly_rule(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $f->task])->set('kind', 'monthly')->set('days', [1, 2])->call('save');

        $this->assertNull($f->task->fresh()->recurrence_days);
    }

    public function test_one_occurrence_can_be_made_the_pattern_for_the_series(): void
    {
        $f = $this->teamFixture();
        $f->task->labels()->attach($f->label);
        $second = $this->finish($this->series($f));
        $second->update(['title' => 'New standard', 'priority' => 'high']);
        $second->labels()->detach();

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $second])->call('useForSeries');
        $third = $this->finish($second->fresh());

        $this->assertSame('New standard', $third->title);
        $this->assertSame('high', $third->priority);
        $this->assertSame(0, $third->labels()->count());
    }

    public function test_a_rule_changed_on_any_occurrence_changes_the_series_and_leaves_finished_ones(): void
    {
        $f = $this->teamFixture();
        $second = $this->finish($this->series($f));

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $second])
            ->set('kind', 'weekly')->set('every', 2)->set('days', [1])->set('limit', '9')->call('save')->assertHasNoErrors();

        $this->assertSame('weekly', $second->fresh()->recurrence_type);
        $this->assertSame([1], $second->fresh()->recurrence_days);
        $this->assertSame(9, $second->fresh()->recurrence_limit);
        $this->assertSame('weekly', $f->task->fresh()->recurrence_type, 'the series root holds the rule');
    }

    public function test_the_panel_shows_a_rule_the_way_it_was_set(): void
    {
        $f = $this->teamFixture();
        $task = $this->series($f, ['kind' => 'yearly', 'every' => 2, 'until' => '2030-01-01', 'limit' => 3, 'missed' => 'skip']);

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $task])
            ->assertSet('kind', 'yearly')->assertSet('every', 2)->assertSet('until', '2030-01-01')->assertSet('limit', 3)->assertSet('missed', 'skip');
    }

    public function test_a_task_starts_repeating_from_the_panel(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $f->task])->set('kind', 'daily')->set('every', 3)->call('save');

        $this->assertTrue($f->task->fresh()->isRecurring());
        $this->assertSame(3, $f->task->fresh()->recurrence_interval);
    }

    public function test_stop_repeating_means_no_more_occurrences(): void
    {
        $f = $this->teamFixture();
        $second = $this->finish($this->series($f));

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $second])->call('stop');

        $this->assertNull($this->finish($second->fresh()));
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function badRules(): array
    {
        return [
            'no such period' => ['kind', 'fortnightly', 'kind'],
            'zero' => ['every', 0, 'every'],
            'an end before it is due' => ['until', '2026-11-01', 'until'],
            'no times at all' => ['limit', -1, 'limit'],
        ];
    }

    #[DataProvider('badRules')]
    public function test_a_rule_that_makes_no_sense_is_refused(string $field, mixed $value, string $error): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-05']);

        Livewire::actingAs($f->editor)->test(TaskRepeat::class, ['task' => $f->task->fresh()])->set($field, $value)->call('save')->assertHasErrors($error);

        $this->assertFalse($f->task->fresh()->isRecurring());
    }

    public function test_a_viewer_cannot_change_a_series(): void
    {
        $f = $this->teamFixture();
        $task = $this->series($f);

        foreach (['save', 'stop', 'useForSeries'] as $method) {
            Livewire::actingAs($f->viewer)->test(TaskRepeat::class, ['task' => $task])->call($method)->assertForbidden();
        }

        $this->assertTrue($task->fresh()->isRecurring());
    }

    public function test_someone_outside_the_team_cannot_open_a_series(): void
    {
        $f = $this->teamFixture();

        $task = $this->series($f);

        Livewire::actingAs($f->outsider)->test(TaskRepeat::class, ['task' => $task])->assertForbidden();
    }

    public function test_finishing_twice_still_makes_one_occurrence(): void
    {
        $f = $this->teamFixture();
        $task = $this->series($f, ['limit' => 5]);

        $this->finish($task);
        app(TaskRecurrenceService::class)->spawnNextOccurrence($task->fresh());

        $this->assertSame(1, Task::where('recurrence_source_id', $task->id)->count());
    }
}
