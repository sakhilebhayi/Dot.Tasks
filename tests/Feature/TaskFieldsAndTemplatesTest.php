<?php

namespace Tests\Feature;

use App\Livewire\Tasks\ManageFields;
use App\Livewire\Tasks\ManageTemplates;
use App\Livewire\Tasks\QuickAdd;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskTable;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskField;
use App\Models\TaskTemplate;
use App\Services\TaskRecurrenceService;
use App\Services\TaskSteps;
use App\Services\TaskTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §8: fields a business decides on, and templates for the work it repeats. */
class TaskFieldsAndTemplatesTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function field(object $f, string $type = 'text', array $options = [], string $name = 'Cost centre'): TaskField
    {
        return TaskField::create(['team_id' => $f->team->id, 'name' => $name, 'type' => $type, 'options' => $options ?: null]);
    }

    private function panel(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskDetail::class)->call('openTask', $f->task->id);
    }

    private function template(object $f, array $values = []): TaskTemplate
    {
        return TaskTemplate::create($values + ['team_id' => $f->team->id, 'name' => 'Inspection', 'title' => 'Inspect the plant', 'priority' => 'high']);
    }

    private function templates(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(ManageTemplates::class);
    }

    public function test_an_admin_adds_each_kind_of_field(): void
    {
        $f = $this->teamFixture();
        $manage = Livewire::actingAs($f->admin)->test(ManageFields::class);

        $manage->set('name', 'Department')->set('type', 'select')->set('choices', "Mining\nFinance\n\n Mining ")->call('add')->assertHasNoErrors();
        $manage->set('name', 'Reference')->set('type', 'text')->call('add')->assertHasNoErrors();

        $this->assertSame(['Mining', 'Finance'], TaskField::where('name', 'Department')->sole()->options);
        $this->assertSame(2, TaskField::count());
    }

    public function test_a_choice_field_needs_choices_and_a_name_is_unique_on_the_team(): void
    {
        $f = $this->teamFixture();
        $this->field($f);
        $manage = Livewire::actingAs($f->admin)->test(ManageFields::class);

        $manage->set('name', 'Department')->set('type', 'select')->call('add')->assertHasErrors('choices');
        $manage->set('name', 'Cost centre')->set('type', 'text')->call('add')->assertHasErrors('name');
    }

    public function test_only_admins_decide_which_fields_exist(): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f);

        Livewire::actingAs($f->editor)->test(ManageFields::class)->assertSee('Cost centre')->set('name', 'Nope')->call('add')->assertForbidden();
        Livewire::actingAs($f->editor)->test(ManageFields::class)->call('remove', $field->id)->assertForbidden();

        $this->assertSame(1, TaskField::count());
    }

    public function test_another_teams_field_cannot_be_removed(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskField::create(['team_id' => $f->outsider->currentTeam->id, 'name' => 'Theirs', 'type' => 'text']);

        Livewire::actingAs($f->admin)->test(ManageFields::class)->call('remove', $theirs->id);

        $this->assertNotNull($theirs->fresh());
    }

    /** @return array<string, array{string, array<int, string>, string}> */
    public static function goodValues(): array
    {
        return [
            'text' => ['text', [], 'CC-14'],
            'number' => ['number', [], '42.5'],
            'date' => ['date', [], '2026-12-01'],
            'choice' => ['select', ['Mining', 'Finance'], 'Finance'],
            'yes' => ['checkbox', [], '1'],
        ];
    }

    #[DataProvider('goodValues')]
    public function test_a_value_of_the_right_kind_is_kept_and_noted(string $type, array $options, string $value): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f, $type, $options);

        $this->panel($f)->call('saveCustomField', $field->id, $value)->assertHasNoErrors();

        $this->assertSame($value, $f->task->fieldValues()->sole()->value);
        $this->assertSame(1, $f->task->activities()->count());
    }

    /** @return array<string, array{string, array<int, string>, string}> */
    public static function badValues(): array
    {
        return [
            'words in a number' => ['number', [], 'lots'],
            'not a date' => ['date', [], 'soon'],
            'an impossible date' => ['date', [], '2026-02-31'],
            'not one of the choices' => ['select', ['Mining'], 'Sales'],
            'not yes' => ['checkbox', [], 'maybe'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_a_value_of_the_wrong_kind_is_refused(string $type, array $options, string $value): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f, $type, $options);

        $this->panel($f)->call('saveCustomField', $field->id, $value)->assertHasErrors('field');

        $this->assertSame(0, $f->task->fieldValues()->count());
    }

    public function test_the_same_value_again_says_nothing_new(): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f);

        $this->panel($f)->call('saveCustomField', $field->id, 'CC-14')->call('saveCustomField', $field->id, 'CC-14');

        $this->assertSame(1, $f->task->activities()->count());
    }

    public function test_clearing_a_field_removes_the_value(): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f);

        $this->panel($f)->call('saveCustomField', $field->id, 'CC-14')->call('saveCustomField', $field->id, '  ');

        $this->assertSame(0, $f->task->fieldValues()->count());
    }

    public function test_another_teams_field_cannot_be_written_and_a_viewer_writes_nothing(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskField::create(['team_id' => $f->outsider->currentTeam->id, 'name' => 'Theirs', 'type' => 'text']);
        $field = $this->field($f);

        $this->panel($f)->call('saveCustomField', $theirs->id, 'x')->assertNotFound();
        $this->panel($f, 'viewer')->call('saveCustomField', $field->id, 'x')->assertForbidden();

        $this->assertSame(0, $f->task->fieldValues()->count());
    }

    public function test_a_field_shows_in_the_list_and_filters_every_view(): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f);
        $f->task->fieldValues()->create(['task_field_id' => $field->id, 'value' => 'CC-14']);
        $f->list->tasks()->create(['title' => 'Other', 'status' => 'todo']);

        $query = ['field' => (string) $field->id, 'value' => 'CC-14'];

        $table = Livewire::withQueryParams($query + ['cols' => 'f'.$field->id])->actingAs($f->editor)->test(TaskTable::class, ['taskList' => $f->list]);
        $this->assertSame(['Fixture task'], $table->instance()->tasks->pluck('title')->all());
        $table->assertSee('CC-14');

        $board = Livewire::withQueryParams($query)->actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list]);
        $board->assertDontSee('Other')->assertSee('Fixture task');
    }

    public function test_a_field_of_another_team_filters_nothing(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskField::create(['team_id' => $f->outsider->currentTeam->id, 'name' => 'Theirs', 'type' => 'text']);

        Livewire::withQueryParams(['field' => (string) $theirs->id, 'value' => 'x'])->actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->assertSee('Fixture task');
    }

    public function test_copies_and_occurrences_bring_their_field_values(): void
    {
        $f = $this->teamFixture();
        $field = $this->field($f);
        $f->task->fieldValues()->create(['task_field_id' => $field->id, 'value' => 'CC-14']);
        $this->actingAs($f->editor);

        $copy = app(TaskSteps::class)->duplicate($f->task->fresh());
        $this->assertSame('CC-14', $copy->fieldValues()->sole()->value);

        $f->task->update(['recurrence_type' => 'daily', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date', 'due_date' => '2026-11-10']);
        $next = app(TaskRecurrenceService::class)->spawnNextOccurrence($f->task->fresh());
        $this->assertSame('CC-14', $next->fieldValues()->sole()->value);
    }

    public function test_a_template_makes_a_task_with_its_steps_person_and_date(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $f = $this->teamFixture();
        $template = $this->template($f, ['due_in_days' => 3, 'assignee_id' => $f->admin->id, 'subtasks' => ['First', 'Second'], 'checklist' => ['Check'], 'label_ids' => [$f->label->id], 'estimated_minutes' => 60]);

        $task = app(TaskTemplates::class)->apply($template, $f->list, $f->editor);

        $this->assertSame('Inspect the plant', $task->title);
        $this->assertSame('high', $task->priority);
        $this->assertSame('2026-11-08', $task->fresh()->due_date->toDateString());
        $this->assertSame($f->admin->id, $task->fresh()->assignee_id);
        $this->assertSame(60, $task->estimated_minutes);
        $this->assertSame(['First', 'Second'], $task->subtasks()->pluck('title')->all());
        $this->assertSame(['Check'], $task->checklistItems()->pluck('title')->all());
        $this->assertSame([$f->label->id], $task->labels()->pluck('labels.id')->all());
        $this->assertSame('Made it from the template Inspection', $task->activities()->where('kind', 'created')->sole()->summary);
        Carbon::setTestNow();
    }

    public function test_a_template_with_someone_who_left_or_a_label_gone_still_works(): void
    {
        $f = $this->teamFixture();
        $theirs = Label::create(['team_id' => $f->outsider->currentTeam->id, 'name' => 'Theirs', 'color' => '#000000']);
        $template = $this->template($f, ['assignee_id' => $f->outsider->id, 'label_ids' => [$theirs->id]]);

        $task = app(TaskTemplates::class)->apply($template, $f->list, $f->editor);

        $this->assertNull($task->fresh()->assignee_id);
        $this->assertSame(0, $task->labels()->count());
    }

    public function test_a_task_is_saved_as_a_template(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['priority' => 'urgent', 'estimated_minutes' => 30]);
        $f->list->tasks()->create(['title' => 'Step', 'status' => 'todo', 'parent_id' => $f->task->id]);
        $f->task->checklistItems()->create(['title' => 'Line']);

        $this->panel($f)->call('saveAsTemplate', 'From a task')->assertSee('Saved as a template.');

        $template = TaskTemplate::sole();
        $this->assertSame('Fixture task', $template->title);
        $this->assertSame('urgent', $template->priority);
        $this->assertSame(['Step'], $template->subtasks);
        $this->assertSame(['Line'], $template->checklist);
        $this->assertSame($f->editor->id, $template->created_by);
    }

    public function test_a_template_needs_a_new_name(): void
    {
        $f = $this->teamFixture();
        $this->template($f);

        $this->panel($f)->call('saveAsTemplate', 'Inspection')->assertHasErrors('template');
        $this->panel($f)->call('saveAsTemplate', '  ')->assertHasErrors('template');

        $this->assertSame(1, TaskTemplate::count());
    }

    public function test_an_editor_writes_a_template_by_hand(): void
    {
        $f = $this->teamFixture();

        $this->templates($f)->call('start')
            ->set('name', 'Monthly close')->set('title', 'Close the month')->set('priority', 'high')->set('dueIn', '5')
            ->set('assignee', (string) $f->admin->id)->set('subtasks', "One\n\nTwo\n")->set('checklist', 'Three')
            ->call('save')->assertHasNoErrors()->assertSet('editing', null);

        $template = TaskTemplate::sole();
        $this->assertSame(['One', 'Two'], $template->subtasks);
        $this->assertSame(5, $template->due_in_days);
        $this->assertSame($f->admin->id, $template->assignee_id);
    }

    public function test_a_template_is_changed_and_loses_nothing(): void
    {
        $f = $this->teamFixture();
        $template = $this->template($f, ['subtasks' => ['One', 'Two']]);

        $this->templates($f)->call('start', $template->id)->assertSet('subtasks', "One\nTwo")->set('title', 'Renamed')->call('save')->assertHasNoErrors();

        $this->assertSame('Renamed', $template->fresh()->title);
        $this->assertSame(['One', 'Two'], $template->fresh()->subtasks);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function badTemplates(): array
    {
        return [
            'no name' => ['name', '', 'name'],
            'no title' => ['title', '', 'title'],
            'no such priority' => ['priority', 'whenever', 'priority'],
            'negative days' => ['dueIn', '-1', 'dueIn'],
        ];
    }

    #[DataProvider('badTemplates')]
    public function test_a_template_that_makes_no_sense_is_refused(string $field, mixed $value, string $error): void
    {
        $f = $this->teamFixture();

        $this->templates($f)->call('start')->set('name', 'A')->set('title', 'B')->set($field, $value)->call('save')->assertHasErrors($error);

        $this->assertSame(0, TaskTemplate::count());
    }

    public function test_a_stranger_cannot_be_written_into_a_template(): void
    {
        $f = $this->teamFixture();

        $this->templates($f)->call('start')->set('name', 'A')->set('title', 'B')->set('assignee', (string) $f->outsider->id)->call('save')->assertHasErrors('assignee');
    }

    public function test_a_viewer_can_use_a_template_on_their_inbox_but_not_change_one(): void
    {
        $f = $this->teamFixture();
        $template = $this->template($f);

        $this->templates($f, 'viewer')->call('use', $template->id)->assertDispatched('open-task');
        $this->assertTrue(Task::where('title', 'Inspect the plant')->sole()->taskList->is_inbox);

        foreach ([['remove', [$template->id]], ['start', [$template->id]], ['addStarters', []], ['placeTemplate', [$template->id]]] as [$method, $arguments]) {
            $this->templates($f, 'viewer')->call($method, ...$arguments)->assertForbidden();
        }

        $this->assertNotNull($template->fresh());
    }

    public function test_a_viewer_cannot_send_a_template_to_a_board(): void
    {
        $f = $this->teamFixture();

        $this->templates($f, 'viewer')->set('board', (string) $f->list->id)->call('use', $this->template($f)->id);

        $this->assertTrue(Task::where('title', 'Inspect the plant')->sole()->taskList->is_inbox);
    }

    public function test_quick_add_cannot_reach_another_teams_template(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskTemplate::create(['team_id' => $f->outsider->currentTeam->id, 'name' => 'Theirs', 'title' => 'Theirs']);

        Livewire::actingAs($f->editor)->test(QuickAdd::class)->call('show')->set('templateId', (string) $theirs->id)->call('save')->assertNotFound();
    }

    public function test_a_template_lands_on_the_board_chosen(): void
    {
        $f = $this->teamFixture();
        $template = $this->template($f);

        $this->templates($f)->set('board', (string) $f->list->id)->call('use', $template->id);

        $this->assertSame($f->list->id, Task::where('title', 'Inspect the plant')->sole()->task_list_id);
    }

    public function test_another_teams_template_stays_out_of_reach(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskTemplate::create(['team_id' => $f->outsider->currentTeam->id, 'name' => 'Theirs', 'title' => 'Theirs']);

        $this->templates($f)->assertDontSee('Theirs')->call('use', $theirs->id)->assertNotFound();
        $this->templates($f)->call('remove', $theirs->id)->assertNotFound();

        $this->assertNotNull($theirs->fresh());
    }

    public function test_the_eight_starters_come_once(): void
    {
        $f = $this->teamFixture();

        $this->templates($f)->call('addStarters')->assertSee('Added 8 templates.')->assertSee('Employee onboarding');
        $this->templates($f)->call('addStarters')->assertSee('Added 0 templates.');

        $this->assertSame(8, TaskTemplate::where('team_id', $f->team->id)->count());
    }

    public function test_templates_are_put_in_order(): void
    {
        $f = $this->teamFixture();
        $a = $this->template($f, ['name' => 'A', 'sort_order' => 1]);
        $b = $this->template($f, ['name' => 'B', 'sort_order' => 2]);

        $this->templates($f)->call('placeTemplate', $b->id, $a->id);

        $this->assertSame(['B', 'A'], TaskTemplate::orderBy('sort_order')->pluck('name')->all());
    }

    public function test_quick_add_starts_from_a_template_and_a_typed_title_names_it(): void
    {
        $f = $this->teamFixture();
        $template = $this->template($f, ['subtasks' => ['First']]);

        Livewire::actingAs($f->editor)->test(QuickAdd::class, ['board' => $f->list->id])
            ->call('show')->set('templateId', (string) $template->id)->set('sentence', 'Inspect plant 4')->call('save')->assertSet('open', false);

        $task = Task::where('title', 'Inspect plant 4')->sole();
        $this->assertSame(['First'], $task->subtasks()->pluck('title')->all());
        $this->assertSame('high', $task->priority);
    }

    public function test_quick_add_with_a_template_and_nothing_typed_keeps_its_title(): void
    {
        $f = $this->teamFixture();
        $template = $this->template($f);

        Livewire::actingAs($f->editor)->test(QuickAdd::class, ['board' => $f->list->id])->call('show')->set('templateId', (string) $template->id)->call('save');

        $this->assertSame(1, Task::where('title', 'Inspect the plant')->count());
    }
}
