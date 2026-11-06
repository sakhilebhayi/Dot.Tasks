<?php

namespace Tests\Feature;

use App\Livewire\Tasks\FindTasks;
use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TeamWork;
use App\Models\AiBreakdownLog;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskList;
use App\Models\User;
use App\Services\AiClient;
use App\Services\FocusRanking;
use App\Services\TaskClarity;
use App\Services\TaskSearchReader;
use App\Services\WorkloadCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §11: help that shows its working, and never changes anything on its own. */
class TaskAssistTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 09:00:00');
        config(['services.anthropic.api_key' => 'test-key-not-real', 'services.anthropic.monthly_token_cap' => 1000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function make(object $f, array $values = []): Task
    {
        return $f->list->tasks()->create($values + ['title' => 'A clear enough title', 'status' => 'todo']);
    }

    private function answer(string $text, array $extra = []): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($extra + [
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ])]);
    }

    private function panel(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskDetail::class)->call('openTask', $f->task->id);
    }

    public function test_no_call_goes_out_without_a_key_or_without_a_cap(): void
    {
        $f = $this->teamFixture();
        Http::fake();

        config(['services.anthropic.monthly_token_cap' => 0]);
        $this->assertFalse(app(AiClient::class)->isConfigured());
        $this->panel($f)->call('aiBreakdown')->assertSee('AI has not been set up');

        config(['services.anthropic.monthly_token_cap' => 1000, 'services.anthropic.api_key' => '']);
        $this->assertFalse(app(AiClient::class)->isConfigured());

        Http::assertNothingSent();
    }

    public function test_a_month_past_its_cap_stops_and_the_next_month_starts_again(): void
    {
        $f = $this->teamFixture();
        AiBreakdownLog::create(['task_id' => $f->task->id, 'user_id' => $f->editor->id, 'prompt' => 'x', 'tokens_used' => 1000]);
        $this->answer('{"subtasks":[{"title":"Plan"}]}');

        $this->panel($f)->call('aiBreakdown')->assertSee("this month's AI allowance is used up");
        Http::assertNothingSent();

        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->assertFalse(app(AiClient::class)->overCap());
    }

    public function test_what_a_call_used_is_counted_both_ways(): void
    {
        $f = $this->teamFixture();
        $this->answer('{"subtasks":[{"title":"Plan"}]}');

        $this->panel($f)->call('aiBreakdown');

        $this->assertSame(150, AiBreakdownLog::sole()->tokens_used);
    }

    public function test_the_request_asks_for_a_shaped_answer_with_a_fallback(): void
    {
        $f = $this->teamFixture();
        $this->answer('{"subtasks":[{"title":"Plan"}]}');

        $this->panel($f)->call('aiBreakdown');

        Http::assertSent(fn (Request $request) => $request['fallbacks'] === 'default'
            && $request['output_config']['format']['type'] === 'json_schema'
            && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'));
    }

    public function test_a_refusal_is_said_plainly_and_nothing_is_suggested(): void
    {
        $f = $this->teamFixture();
        $this->answer('', ['stop_reason' => 'refusal', 'content' => []]);

        $this->panel($f)->call('aiBreakdown')->assertSee('declined this request')->assertSet('suggested', []);
    }

    public function test_a_lost_connection_is_logged_and_said(): void
    {
        $f = $this->teamFixture();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->panel($f)->call('aiBreakdown')->assertSee('did not return a usable answer');

        $this->assertStringContainsString('unreachable', AiBreakdownLog::sole()->response);
    }

    public function test_suggestions_change_nothing_until_a_person_chooses(): void
    {
        $f = $this->teamFixture();
        $this->answer('{"subtasks":[{"title":"Plan","priority":"high","estimated_minutes":15},{"title":"Build","priority":"low","estimated_minutes":30}]}');

        $panel = $this->panel($f)->call('aiBreakdown');
        $this->assertSame(0, $f->task->subtasks()->count());

        $panel->call('acceptSuggested', [1])->assertSet('suggested', []);

        $sub = $f->task->subtasks()->sole();
        $this->assertSame('Build', $sub->title);
        $this->assertSame('low', $sub->priority);
        $this->assertSame(30, $sub->estimated_minutes);
    }

    public function test_suggestions_can_be_dismissed_and_cannot_be_forged(): void
    {
        $f = $this->teamFixture();
        $this->answer('{"subtasks":[{"title":"Plan"}]}');

        $panel = $this->panel($f)->call('aiBreakdown');
        $panel->call('acceptSuggested', [7]);
        $this->assertSame(0, $f->task->subtasks()->count());

        $this->expectException(\Throwable::class);
        $panel->set('suggested', [['title' => 'Forged', 'priority' => 'low', 'estimated_minutes' => null]]);
    }

    public function test_a_viewer_gets_no_suggestions_and_adds_none(): void
    {
        $f = $this->teamFixture();
        Http::fake();

        $this->panel($f, 'viewer')->call('aiBreakdown')->assertForbidden();
        $this->panel($f, 'viewer')->call('acceptSuggested', [0])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_summary_ties_each_point_to_its_comments_and_drops_what_it_cannot(): void
    {
        $f = $this->teamFixture();
        foreach (['Quote is in', 'Approved the quote', 'Order placed'] as $body) {
            TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->admin->id, 'body' => $body]);
        }
        $this->answer('{"points":[{"text":"The order is placed.","comments":[3]},{"text":"Made up.","comments":[99]},{"text":"No source.","comments":[]}]}');

        $this->panel($f)->call('aiSummary')
            ->assertSee('The order is placed.')
            ->assertSee('Admin Person Fixture, 5 Nov')
            ->assertDontSee('Made up.')
            ->assertDontSee('No source.');

        $this->assertSame(3, $f->task->comments()->count(), 'a summary is shown and never saved');
    }

    public function test_a_summary_never_carries_notes_a_viewer_cannot_read(): void
    {
        $f = $this->teamFixture();
        TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->admin->id, 'body' => 'Plain one']);
        TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->admin->id, 'body' => 'Between us', 'internal' => true]);
        $this->answer('{"points":[{"text":"Plain.","comments":[1]}]}');

        $this->panel($f, 'viewer')->call('aiSummary');

        Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'Plain one') && ! str_contains($request['messages'][0]['content'], 'Between us'));
    }

    public function test_a_summary_with_nothing_traceable_is_refused_not_shown(): void
    {
        $f = $this->teamFixture();
        TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->admin->id, 'body' => 'One']);
        $this->answer('{"points":[{"text":"Invented.","comments":[50]}]}');

        $this->panel($f)->call('aiSummary')->assertSet('summary', [])->assertSee('AI summary unavailable');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function gaps(): array
    {
        return [
            'short title' => [['title' => 'Fix it'], 'The title is short'],
            'a vague title' => [['title' => 'follow up'], 'The title is short'],
            'nobody on it' => [[], 'Nobody is on it.'],
            'no date' => [[], 'It has no due date.'],
            'urgent and no description' => [['priority' => 'urgent'], 'It matters, and has no description.'],
            'no definition of done' => [[], 'Nothing says what done means.'],
        ];
    }

    #[DataProvider('gaps')]
    public function test_what_a_task_leaves_open_is_named(array $values, string $gap): void
    {
        $f = $this->teamFixture();
        $task = $this->make($f, $values);

        $this->assertContains(true, array_map(fn (string $line) => str_starts_with($line, $gap), app(TaskClarity::class)->gaps($task)));
    }

    public function test_a_clear_task_has_no_gaps(): void
    {
        $f = $this->teamFixture();
        $task = $this->make($f, ['title' => 'Renew the fleet insurance', 'assignee_id' => $f->admin->id, 'due_date' => '2026-11-10', 'description' => 'Details', 'priority' => 'high', 'completion_criteria' => 'Policy on file']);

        $this->assertSame([], app(TaskClarity::class)->gaps($task));
    }

    public function test_only_work_that_matters_is_asked_for_a_description(): void
    {
        $f = $this->teamFixture();

        $this->assertNotContains('It matters, and has no description.', app(TaskClarity::class)->gaps($this->make($f)));
    }

    public function test_the_gaps_show_to_someone_who_can_fix_them(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->assertSee('could be clearer');
        $this->panel($f, 'viewer')->assertDontSee('could be clearer');
    }

    private function ranked(object $f): array
    {
        return app(FocusRanking::class)->rank(Task::with(['blocking:id,status', 'blockedBy:id,status'])->get());
    }

    public function test_focus_puts_first_what_is_late_or_holding_others_up_and_says_why(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['title' => 'Quiet']);
        $this->make($f, ['title' => 'Late', 'due_date' => '2026-11-02']);
        $this->make($f, ['title' => 'Soon', 'due_date' => '2026-11-06']);
        $blocker = $this->make($f, ['title' => 'Holding up', 'priority' => 'urgent']);
        $this->make($f, ['title' => 'Waiting'])->blockedBy()->attach($blocker);

        $rows = $this->ranked($f);

        $this->assertSame(['Holding up', 'Late', 'Soon'], array_map(fn ($r) => $r['task']->title, array_slice($rows, 0, 3)));
        $this->assertSame(['Urgent priority', '1 other task waiting on this'], $rows[0]['reasons']);
        $this->assertSame(['3 days overdue'], $rows[1]['reasons']);
        $this->assertNotContains('Quiet', array_map(fn ($r) => $r['task']->title, $rows), 'no reason, no suggestion');
    }

    public function test_something_blocked_does_not_lead(): void
    {
        $f = $this->teamFixture();
        $first = $this->make($f, ['title' => 'Plain', 'due_date' => '2026-11-07']);
        $stuck = $this->make($f, ['title' => 'Stuck', 'due_date' => '2026-11-05']);
        $stuck->blockedBy()->attach($this->make($f, ['title' => 'Other']));

        $rows = $this->ranked($f);

        $this->assertSame('Plain', $rows[0]['task']->title);
        $this->assertContains('Blocked for now', end($rows)['reasons']);
    }

    public function test_my_day_shows_the_suggestions_with_their_reasons_and_changes_nothing(): void
    {
        $f = $this->teamFixture();
        $task = $this->make($f, ['title' => 'Late one', 'due_date' => '2026-11-02', 'assignee_id' => $f->editor->id]);

        Livewire::actingAs($f->editor)->test(MyDay::class)->assertSee('Worth doing first')->assertSee('3 days overdue');

        $this->assertSame('medium', $task->fresh()->priority);
    }

    public function test_workload_flags_are_counts_anyone_can_check(): void
    {
        $f = $this->teamFixture();
        $make = fn (array $values) => new Task($values + ['title' => 'T', 'status' => 'todo']);
        $held = collect(range(1, 6))->map(fn () => $make(['due_date' => '2026-11-10', 'estimated_minutes' => 450]));

        $flags = app(WorkloadCheck::class)->flags($held, 2.0);

        $this->assertSame(['Holds 6 tasks. The team average is 2.', '45 hours due in 7 days, over 40.', '6 tasks due on Tuesday.'], $flags);
    }

    public function test_an_ordinary_load_raises_no_flag(): void
    {
        $make = fn (array $values) => new Task($values + ['title' => 'T', 'status' => 'todo']);

        $this->assertSame([], app(WorkloadCheck::class)->flags(collect([$make(['due_date' => '2026-11-10', 'estimated_minutes' => 60])]), 1.0));
    }

    public function test_a_load_near_the_average_is_not_flagged_however_many_tasks(): void
    {
        $held = collect(range(1, 6))->map(fn () => new Task(['title' => 'T', 'status' => 'todo']));

        $this->assertSame([], app(WorkloadCheck::class)->flags($held, 5.0));
    }

    public function test_the_team_page_shows_the_flags(): void
    {
        $f = $this->teamFixture();
        foreach (range(1, 3) as $n) {
            $this->make($f, ['assignee_id' => $f->editor->id, 'due_date' => '2026-11-10']);
        }

        Livewire::actingAs($f->admin)->test(TeamWork::class)->assertSee('3 tasks due on Tuesday.');
    }

    /** @return list<string> */
    private function search(object $f, string $question): array
    {
        return Livewire::actingAs($f->editor)->test(FindTasks::class)->set('query', $question)->instance()->results->pluck('title')->all();
    }

    public function test_the_example_in_the_brief_is_understood(): void
    {
        $f = $this->teamFixture();
        $f->label->update(['name' => 'Maintenance']);
        $wanted = $this->make($f, ['title' => 'Grease the bearings', 'assignee_id' => $f->admin->id, 'due_date' => '2026-11-01']);
        $wanted->labels()->attach($f->label);
        $this->make($f, ['title' => 'Grease the belts', 'assignee_id' => $f->admin->id, 'due_date' => '2026-12-01'])->labels()->attach($f->label);
        $this->make($f, ['title' => 'Other late work', 'assignee_id' => $f->admin->id, 'due_date' => '2026-11-01']);
        $this->make($f, ['title' => 'Nobody on it', 'due_date' => '2026-11-01'])->labels()->attach($f->label);

        $read = app(TaskSearchReader::class)->read('Show me the maintenance tasks assigned to my team that are overdue.', $f->team, $f->editor);

        $this->assertSame('', $read['words']);
        $this->assertEqualsCanonicalizing(['Overdue', 'Given to someone on the team', 'Label Maintenance'], $read['understood']);
        $this->assertSame(['Grease the bearings'], $this->search($f, 'Show me the maintenance tasks assigned to my team that are overdue.'));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function questions(): array
    {
        return [
            'mine' => ['my tasks', ['Mine']],
            'a person' => ['assigned to admin', ['Theirs']],
            'nobody' => ['unassigned', ['Fixture task', 'Late pump']],
            'due today' => ['due today', ['Mine']],
            'finished' => ['finished work', ['Finished']],
            'urgent' => ['urgent tasks', ['Theirs']],
            'plain words' => ['pump', ['Late pump']],
            'words with a state' => ['overdue pump', ['Late pump']],
        ];
    }

    #[DataProvider('questions')]
    public function test_plain_questions_find_the_right_tasks(string $question, array $titles): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['title' => 'Mine', 'assignee_id' => $f->editor->id, 'due_date' => '2026-11-05']);
        $this->make($f, ['title' => 'Theirs', 'assignee_id' => $f->admin->id, 'priority' => 'urgent']);
        $this->make($f, ['title' => 'Late pump', 'due_date' => '2026-11-01']);
        $this->make($f, ['title' => 'Finished', 'status' => 'done', 'assignee_id' => $f->owner->id]);

        $found = $this->search($f, $question);
        sort($found);
        sort($titles);

        $this->assertSame($titles, $found);
    }

    public function test_a_blocked_search_finds_blocked_work(): void
    {
        $f = $this->teamFixture();
        $f->task->blockedBy()->attach($this->make($f, ['title' => 'First']));

        $this->assertSame(['Fixture task'], $this->search($f, 'blocked tasks'));
    }

    public function test_a_name_that_fits_two_people_stays_as_words(): void
    {
        $f = $this->teamFixture();
        foreach (['James Dlamini', 'James Nkosi'] as $name) {
            $f->team->users()->attach(User::factory()->create(['name' => $name]), ['role' => 'editor']);
        }

        $read = app(TaskSearchReader::class)->read('assigned to James', $f->team, $f->editor);

        $this->assertSame([], $read['understood']);
        $this->assertSame('james', $read['words']);
    }

    public function test_a_name_that_is_nobody_stays_as_words(): void
    {
        $f = $this->teamFixture();

        $read = app(TaskSearchReader::class)->read('assigned to Zanele', $f->team, $f->editor);

        $this->assertSame([], $read['understood']);
        $this->assertSame('zanele', $read['words']);
    }

    public function test_search_says_what_it_understood_and_stays_inside_the_team(): void
    {
        $f = $this->teamFixture();
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = TaskList::create(['team_id' => $other->currentTeam->id, 'owner_id' => $other->id, 'name' => 'Elsewhere']);
        $theirs->tasks()->create(['title' => 'Their overdue', 'status' => 'todo', 'due_date' => '2026-11-01']);
        $this->make($f, ['title' => 'Our overdue', 'due_date' => '2026-11-01']);

        Livewire::actingAs($f->editor)->test(FindTasks::class)->call('show')->set('query', 'overdue')
            ->assertSee('Overdue')->assertSee('Our overdue')->assertDontSee('Their overdue');
    }
}
