<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskBoard;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class TaskBoardFiltersTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private object $f;

    private Label $urgentLabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->f = $f = $this->teamFixture();
        $f->task->delete(); // the fixture task would only add noise here
        $this->urgentLabel = Label::create(['team_id' => $f->team->id, 'name' => 'Customer facing', 'color' => '#ef4444']);

        $make = function (string $title, array $attrs = []) use ($f) {
            return $f->list->tasks()->create($attrs + ['title' => $title, 'status' => 'todo']);
        };

        $make('Reconcile the quarterly ledger', [
            'priority' => 'high', 'assignee_id' => $f->editor->id,
            'due_date' => now()->subDays(3)->toDateString(), 'description' => 'Numbers must match the bank statement',
        ]);
        $make('Prepare onboarding handbook', ['priority' => 'low', 'assignee_id' => $f->viewer->id, 'due_date' => now()->toDateString()]);
        $make('Replace the lobby signage', ['priority' => 'medium', 'description' => 'Contractor visit pending']);
        $make('Finished long ago but overdue', ['priority' => 'high', 'status' => 'done', 'due_date' => now()->subDays(9)->toDateString()]);
        $make('Pay the 100% deposit invoice', ['priority' => 'urgent', 'assignee_id' => $f->editor->id, 'due_date' => now()->addDays(5)->toDateString()])
            ->labels()->attach($this->urgentLabel->id);
    }

    private function board(string $as = 'viewer', array $query = [])
    {
        return Livewire::actingAs($this->f->{$as})->withQueryParams($query)->test(TaskBoard::class, ['taskList' => $this->f->list]);
    }

    public function test_with_no_filter_every_card_shows(): void
    {
        $this->board()
            ->assertSee('Reconcile the quarterly ledger')
            ->assertSee('Prepare onboarding handbook')
            ->assertSee('Replace the lobby signage')
            ->assertSee('Pay the 100% deposit invoice');
    }

    public function test_text_matches_title_and_description_ignoring_case(): void
    {
        $this->board()->set('search', 'LEDGER')
            ->assertSee('Reconcile the quarterly ledger')
            ->assertDontSee('Replace the lobby signage');

        $this->board()->set('search', 'contractor visit')
            ->assertSee('Replace the lobby signage')
            ->assertDontSee('Reconcile the quarterly ledger');
    }

    public function test_a_percent_sign_in_the_search_is_matched_literally(): void
    {
        $this->board()->set('search', '100%')
            ->assertSee('Pay the 100% deposit invoice')
            ->assertDontSee('Replace the lobby signage')
            ->assertDontSee('Reconcile the quarterly ledger');

        $this->board()->set('search', '%')
            ->assertSee('Pay the 100% deposit invoice')
            ->assertDontSee('Prepare onboarding handbook');
    }

    public function test_filter_by_assignee_me_none_and_a_person(): void
    {
        $this->board('editor')->set('assignee', 'me')
            ->assertSee('Reconcile the quarterly ledger')
            ->assertSee('Pay the 100% deposit invoice')
            ->assertDontSee('Prepare onboarding handbook')
            ->assertDontSee('Replace the lobby signage');

        $this->board()->set('assignee', 'none')
            ->assertSee('Replace the lobby signage')
            ->assertDontSee('Reconcile the quarterly ledger');

        $this->board()->set('assignee', (string) $this->f->viewer->id)
            ->assertSee('Prepare onboarding handbook')
            ->assertDontSee('Reconcile the quarterly ledger');
    }

    public function test_filter_by_priority(): void
    {
        $this->board()->set('priority', 'urgent')
            ->assertSee('Pay the 100% deposit invoice')
            ->assertDontSee('Reconcile the quarterly ledger');

        $this->board()->set('priority', 'low')
            ->assertSee('Prepare onboarding handbook')
            ->assertDontSee('Pay the 100% deposit invoice');
    }

    public function test_filter_by_label(): void
    {
        $this->board()->set('labelId', (string) $this->urgentLabel->id)
            ->assertSee('Pay the 100% deposit invoice')
            ->assertDontSee('Reconcile the quarterly ledger')
            ->assertDontSee('Replace the lobby signage');
    }

    public function test_filter_by_due_state(): void
    {
        $this->board()->set('due', 'overdue')
            ->assertSee('Reconcile the quarterly ledger')
            ->assertDontSee('Finished long ago but overdue') // done tasks are never overdue
            ->assertDontSee('Prepare onboarding handbook');

        $this->board()->set('due', 'today')
            ->assertSee('Prepare onboarding handbook')
            ->assertDontSee('Reconcile the quarterly ledger');

        $this->board()->set('due', 'none')
            ->assertSee('Replace the lobby signage')
            ->assertDontSee('Prepare onboarding handbook')
            ->assertDontSee('Pay the 100% deposit invoice');
    }

    public function test_filters_combine(): void
    {
        $this->board('editor')->set('assignee', 'me')->set('priority', 'high')
            ->assertSee('Reconcile the quarterly ledger')
            ->assertDontSee('Pay the 100% deposit invoice');

        $this->board('editor')->set('assignee', 'me')->set('priority', 'high')->set('search', 'no such words anywhere')
            ->assertDontSee('Reconcile the quarterly ledger')
            ->assertSee('No tasks match');
    }

    public function test_filters_are_read_from_the_url_so_a_filtered_board_can_be_bookmarked(): void
    {
        $this->actingAs($this->f->editor)
            ->get(route('task-lists.show', $this->f->list).'?priority=urgent&q=deposit')
            ->assertOk()
            ->assertSee('Pay the 100% deposit invoice')
            ->assertDontSee('Reconcile the quarterly ledger');

        $this->board('editor', ['q' => 'ledger', 'assignee' => 'me', 'priority' => 'high', 'due' => 'overdue', 'label' => (string) $this->urgentLabel->id])
            ->assertSet('search', 'ledger')
            ->assertSet('assignee', 'me')
            ->assertSet('priority', 'high')
            ->assertSet('due', 'overdue')
            ->assertSet('labelId', (string) $this->urgentLabel->id);
    }

    public function test_every_filter_is_a_url_bound_livewire_property(): void
    {
        foreach (['search', 'assignee', 'priority', 'labelId', 'due', 'show'] as $property) {
            $attributes = (new ReflectionProperty(TaskBoard::class, $property))->getAttributes(Url::class);
            $this->assertNotEmpty($attributes, "{$property} must carry #[Url]");
        }
    }

    public function test_junk_in_the_url_is_ignored_not_fatal(): void
    {
        $this->actingAs($this->f->viewer)
            ->get(route('task-lists.show', $this->f->list).'?priority=banana&assignee=abc&label=zzz&due=someday&show=hax')
            ->assertOk()
            ->assertSee('Reconcile the quarterly ledger')
            ->assertSee('Replace the lobby signage');
    }

    public function test_an_assignee_or_label_from_outside_the_team_filters_nothing_in(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $foreign = Label::create(['team_id' => $stranger->currentTeam->id, 'name' => 'Foreign label', 'color' => '#22c55e']);

        // Treated as no filter at all rather than as a way to probe other teams.
        $this->board()->set('assignee', (string) $stranger->id)->assertSee('Reconcile the quarterly ledger');
        $this->board()->set('labelId', (string) $foreign->id)->assertSee('Reconcile the quarterly ledger');
    }

    public function test_the_board_never_shows_another_teams_tasks_even_for_a_matching_search(): void
    {
        $other = $this->teamFixture();
        $other->task->update(['title' => 'Reconcile the quarterly ledger of the other team']);
        // Even a hand-built list that points at this board id can only ever return this board's rows.
        $this->board()->set('search', 'quarterly ledger')
            ->assertSee('Reconcile the quarterly ledger')
            ->assertDontSee('of the other team');
    }

    public function test_the_filter_bar_lists_only_team_members_and_team_labels(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create(['name' => 'Samantha Stranger Elsewhere']);
        Label::create(['team_id' => $stranger->currentTeam->id, 'name' => 'Foreign label', 'color' => '#22c55e']);

        $this->board()
            ->assertSee('Customer facing')
            ->assertSee($this->f->editor->name)
            ->assertDontSee('Samantha Stranger Elsewhere')
            ->assertDontSee('Foreign label');
    }

    public function test_clearing_filters_resets_them(): void
    {
        $this->board('editor')
            ->set('assignee', 'me')->set('priority', 'high')->set('search', 'ledger')->set('due', 'overdue')->set('labelId', (string) $this->urgentLabel->id)
            ->call('clearFilters')
            ->assertSet('assignee', '')
            ->assertSet('priority', '')
            ->assertSet('search', '')
            ->assertSet('due', '')
            ->assertSet('labelId', '');
    }

    public function test_a_filtered_board_says_so_when_nothing_matches(): void
    {
        $this->board()->set('priority', 'urgent')->set('due', 'overdue')
            ->assertSee('No tasks match these filters');
    }

    public function test_the_task_list_model_is_the_only_board_the_filter_reads(): void
    {
        $second = TaskList::create(['team_id' => $this->f->team->id, 'owner_id' => $this->f->owner->id, 'name' => 'Second board']);
        $second->tasks()->create(['title' => 'Belongs to the second board', 'status' => 'todo']);

        $this->board()->assertDontSee('Belongs to the second board');
        $this->assertSame(1, Task::where('title', 'Belongs to the second board')->count());
    }
}
