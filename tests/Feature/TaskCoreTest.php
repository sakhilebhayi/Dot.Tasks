<?php

namespace Tests\Feature;

use App\Livewire\Tasks\FindTasks;
use App\Livewire\Tasks\QuickAdd;
use App\Livewire\Tasks\SmartViews;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\InboxLists;
use App\Services\TaskEditor;
use App\Services\TaskSentenceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §5: every field editable where it sits, manual steps, finishing, history, one-sentence capture, the Inbox. */
class TaskCoreTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function panel(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskDetail::class)->call('openTask', $f->task->id);
    }

    /** @return array<string, array{string, mixed, mixed}> */
    public static function fields(): array
    {
        return [
            'title' => ['title', 'A better name', 'A better name'],
            'description' => ['description', 'What this means', 'What this means'],
            'due' => ['due_date', '2026-12-01', '2026-12-01'],
            'start' => ['start_date', '2026-11-20', '2026-11-20'],
            'estimate' => ['estimated_minutes', '90', 90],
            'time spent' => ['actual_minutes', '45', 45],
            'what done means' => ['completion_criteria', 'Signed off', 'Signed off'],
            'priority' => ['priority', 'urgent', 'urgent'],
        ];
    }

    #[DataProvider('fields')]
    public function test_each_field_saves_where_it_sits_and_leaves_a_line_of_history(string $field, mixed $value, mixed $expected): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->call('saveField', $field, $value)->assertHasNoErrors();

        $task = $f->task->fresh();
        $stored = $task->{$field} instanceof \DateTimeInterface ? $task->{$field}->format('Y-m-d') : $task->{$field};
        $this->assertSame($expected, $stored);
        $this->assertSame(1, $task->activities()->count());
        $this->assertSame($f->editor->id, $task->activities()->first()->user_id);
    }

    public function test_saving_the_same_value_says_nothing_happened(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->call('saveField', 'title', 'Fixture task');

        $this->assertSame(0, $f->task->activities()->count());
    }

    public function test_a_blank_field_is_cleared_but_a_blank_title_is_refused(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['description' => 'Something']);

        $this->panel($f)
            ->call('saveField', 'description', '  ')
            ->call('saveField', 'title', '   ')
            ->assertHasErrors('title');

        $this->assertNull($f->task->fresh()->description);
        $this->assertSame('Fixture task', $f->task->fresh()->title);
    }

    public function test_a_start_after_the_due_date_is_refused_with_a_plain_reason(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-10']);

        $this->panel($f)->call('saveField', 'start_date', '2026-11-11')->assertHasErrors('start_date');

        $this->assertNull($f->task->fresh()->start_date);
    }

    public function test_a_due_date_before_the_start_is_refused_too(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['start_date' => '2026-11-10']);

        $this->panel($f)->call('saveField', 'due_date', '2026-11-09')->assertHasErrors('due_date');
    }

    public function test_only_the_listed_fields_can_be_set(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->call('saveField', 'task_list_id', 999)->assertHasErrors('task');
        $this->panel($f)->call('saveField', 'priority', 'whenever')->assertHasErrors('priority');

        $this->assertSame($f->list->id, $f->task->fresh()->task_list_id);
    }

    /** @return array<string, array{string, list<mixed>}> */
    public static function writes(): array
    {
        return [
            'edit' => ['saveField', ['title', 'Nope']],
            'collaborator' => ['toggleCollaborator', [1]],
            'duplicate' => ['duplicate', []],
            'finish' => ['finish', []],
        ];
    }

    #[DataProvider('writes')]
    public function test_a_viewer_cannot_change_a_thing(string $method, array $arguments): void
    {
        $f = $this->teamFixture();

        $this->panel($f, 'viewer')->call($method, ...$arguments)->assertForbidden();

        $this->assertSame('Fixture task', $f->task->fresh()->title);
        $this->assertSame(1, Task::count());
    }

    public function test_a_viewer_sees_the_values_without_the_controls(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['estimated_minutes' => 30]);

        $this->panel($f, 'viewer')
            ->assertSee('30 minutes')
            ->assertDontSeeHtml('wire:change="saveField')
            ->assertDontSee('Add a subtask');
    }

    public function test_subtasks_are_added_by_hand_and_ticked_off(): void
    {
        $f = $this->teamFixture();

        $panel = $this->panel($f)->set('newSubtask', 'Draft it')->call('addSubtask')->assertSet('newSubtask', '');
        $sub = $f->task->subtasks()->sole();
        $this->assertSame('Draft it', $sub->title);
        $this->assertSame($f->task->task_list_id, $sub->task_list_id);

        $panel->call('toggleSubtask', $sub->id);
        $this->assertSame('done', $sub->fresh()->status);
        $this->assertNotNull($sub->fresh()->completed_at);

        $panel->call('toggleSubtask', $sub->id);
        $this->assertSame('todo', $sub->fresh()->status);
    }

    public function test_a_task_on_another_board_cannot_be_ticked_through_this_one(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Someone else', 'status' => 'todo']);

        $this->panel($f)->call('toggleSubtask', $other->id)->assertNotFound();

        $this->assertSame('todo', $other->fresh()->status);
    }

    public function test_an_empty_step_is_refused(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)
            ->call('addSubtask')->assertHasErrors('newSubtask')
            ->call('addChecklistItem')->assertHasErrors('newChecklistItem');
    }

    public function test_a_checklist_is_kept_and_a_line_can_become_a_subtask(): void
    {
        $f = $this->teamFixture();

        $panel = $this->panel($f)->set('newChecklistItem', 'Check figures')->call('addChecklistItem');
        $first = $f->task->checklistItems()->sole();
        $panel->call('toggleChecklistItem', $first->id);
        $this->assertNotNull($first->fresh()->done_at);
        $this->assertSame($f->editor->id, $first->fresh()->done_by);

        $panel->call('promoteChecklistItem', $first->id);
        $this->assertSame(0, $f->task->checklistItems()->count());
        $sub = $f->task->subtasks()->sole();
        $this->assertSame('Check figures', $sub->title);
        $this->assertSame('done', $sub->status, 'a ticked line stays done when it becomes a subtask');
        $this->assertNotNull($sub->completed_at);
    }

    public function test_a_checklist_line_can_be_unticked_and_removed(): void
    {
        $f = $this->teamFixture();
        $item = $f->task->checklistItems()->create(['title' => 'Line']);

        $panel = $this->panel($f)->call('toggleChecklistItem', $item->id)->call('toggleChecklistItem', $item->id);
        $this->assertNull($item->fresh()->done_at);

        $panel->call('removeChecklistItem', $item->id);
        $this->assertSame(0, $f->task->checklistItems()->count());
    }

    public function test_another_tasks_checklist_cannot_be_reached_through_this_one(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Other', 'status' => 'todo']);
        $item = $other->checklistItems()->create(['title' => 'Theirs']);

        foreach (['toggleChecklistItem', 'removeChecklistItem', 'promoteChecklistItem'] as $method) {
            $this->panel($f)->call($method, $item->id)->assertNotFound();
        }

        $this->assertNotNull($item->fresh());
        $this->assertNull($item->fresh()->done_at);
    }

    public function test_collaborators_come_only_from_the_team(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->call('toggleCollaborator', $f->admin->id)->call('toggleCollaborator', $f->outsider->id);

        $this->assertSame([$f->admin->id], $f->task->collaborators()->pluck('users.id')->all());

        $this->panel($f)->call('toggleCollaborator', $f->admin->id);
        $this->assertSame(0, $f->task->collaborators()->count());
    }

    public function test_duplicate_brings_the_details_and_steps_but_starts_fresh(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['status' => 'done', 'completed_at' => now(), 'completed_by' => $f->owner->id, 'completion_note' => 'Done', 'actual_minutes' => 20, 'estimated_minutes' => 30]);
        $f->task->labels()->attach($f->label);
        $f->task->collaborators()->attach($f->admin);
        $f->list->tasks()->create(['title' => 'Step', 'status' => 'done', 'parent_id' => $f->task->id, 'completed_at' => now()]);
        $f->task->checklistItems()->create(['title' => 'Line', 'done_at' => now()]);

        $this->panel($f)->call('duplicate')->assertDispatched('task-updated');

        $copy = Task::where('title', 'Fixture task (copy)')->sole();
        $this->assertSame('todo', $copy->status);
        $this->assertNull($copy->completed_at);
        $this->assertNull($copy->completion_note);
        $this->assertNull($copy->actual_minutes);
        $this->assertSame(30, $copy->estimated_minutes);
        $this->assertSame($f->editor->id, $copy->creator_id);
        $this->assertSame([$f->label->id], $copy->labels()->pluck('labels.id')->all());
        $this->assertSame([$f->admin->id], $copy->collaborators()->pluck('users.id')->all());
        $this->assertSame('todo', $copy->subtasks()->sole()->status);
        $this->assertNull($copy->subtasks()->sole()->completed_at);
        $this->assertNull($copy->checklistItems()->sole()->done_at);
    }

    public function test_finishing_records_who_when_and_the_note_and_reopening_clears_them(): void
    {
        $f = $this->teamFixture();

        $panel = $this->panel($f)->set('completionNote', 'Sent to finance')->call('finish')->assertHasNoErrors();

        $task = $f->task->fresh();
        $this->assertSame('done', $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertSame($f->editor->id, $task->completed_by);
        $this->assertSame('Sent to finance', $task->completion_note);

        $panel->call('updateStatus', 'todo')->assertHasNoErrors();
        $task = $task->fresh();
        $this->assertSame('todo', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completed_by);
    }

    public function test_a_blocked_task_cannot_be_finished_and_keeps_its_note(): void
    {
        $f = $this->teamFixture();
        $blocker = $f->list->tasks()->create(['title' => 'First', 'status' => 'todo']);
        $f->task->blockedBy()->attach($blocker);

        $this->panel($f)->set('completionNote', 'All done')->call('finish')->assertSet('completionNote', 'All done');

        $this->assertSame('todo', $f->task->fresh()->status);
        $this->assertNull($f->task->fresh()->completion_note);
    }

    public function test_the_history_reads_as_sentences_and_names_who(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)
            ->call('saveField', 'title', 'New name')
            ->call('assignTask', $f->admin->id)
            ->assertSee('Admin Person Fixture')
            ->assertSee('renamed it to New name');

        $this->assertSame(2, $f->task->activities()->count());
    }

    public function test_the_example_in_the_brief_reads_as_a_task(): void
    {
        $f = $this->teamFixture();
        $james = User::factory()->create(['name' => 'James Dlamini']);
        $f->team->users()->attach($james, ['role' => 'editor']);

        $read = app(TaskSentenceReader::class)->read('Prepare the monthly operational report for Friday and assign it to James.', $f->team, Carbon::parse('2026-11-05'));

        $this->assertSame('Prepare the monthly operational report', $read['title']);
        $this->assertSame('2026-11-06', $read['due_date']);
        $this->assertSame($james->id, $read['assignee_id']);
        $this->assertNull($read['unknown_person']);
    }

    /** @return array<string, array{string, string, string|null}> */
    public static function dates(): array
    {
        return [
            'today' => ['Call the bank today', 'Call the bank', '2026-11-05'],
            'tonight' => ['Call the bank tonight', 'Call the bank', '2026-11-05'],
            'tomorrow' => ['Call the bank tomorrow', 'Call the bank', '2026-11-06'],
            'by a weekday' => ['Call the bank by Monday', 'Call the bank', '2026-11-09'],
            'the same weekday counts today' => ['Call the bank on Thursday', 'Call the bank', '2026-11-05'],
            'a weekday at the end' => ['Call the bank Monday', 'Call the bank', '2026-11-09'],
            'next weekday' => ['Call the bank next Friday', 'Call the bank', '2026-11-13'],
            'in days' => ['Call the bank in 3 days', 'Call the bank', '2026-11-08'],
            'in weeks' => ['Call the bank in 2 weeks', 'Call the bank', '2026-11-19'],
            'end of month' => ['Call the bank by end of month', 'Call the bank', '2026-11-30'],
            'a day and month' => ['Call the bank on 14 January', 'Call the bank', '2027-01-14'],
            'a month and day' => ['Call the bank by December 2', 'Call the bank', '2026-12-02'],
            'no date' => ['Call the bank', 'Call the bank', null],
            'a word that looks like a date' => ['Plan the Friday market stall', 'Plan the Friday market stall', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_dates_are_read_from_the_sentence(string $sentence, string $title, ?string $due): void
    {
        $f = $this->teamFixture();

        $read = app(TaskSentenceReader::class)->read($sentence, $f->team, Carbon::parse('2026-11-05'));

        $this->assertSame($title, $read['title']);
        $this->assertSame($due, $read['due_date']);
    }

    public function test_a_priority_and_an_at_name_are_read(): void
    {
        $f = $this->teamFixture();

        $read = app(TaskSentenceReader::class)->read('Urgent: renew the licence @Admin', $f->team, Carbon::parse('2026-11-05'));

        $this->assertSame('urgent', $read['priority']);
        $this->assertSame($f->admin->id, $read['assignee_id']);
        $this->assertSame('Renew the licence', $read['title']);
    }

    public function test_a_name_not_on_the_team_is_said_out_loud_not_guessed(): void
    {
        $f = $this->teamFixture();

        $read = app(TaskSentenceReader::class)->read('Sort the files and assign it to Zanele', $f->team, Carbon::parse('2026-11-05'));

        $this->assertNull($read['assignee_id']);
        $this->assertSame('Zanele', $read['unknown_person']);
        $this->assertSame('Sort the files', $read['title']);
    }

    public function test_two_people_with_the_same_first_name_is_a_question_not_a_guess(): void
    {
        $f = $this->teamFixture();
        foreach (['James Dlamini', 'James Nkosi'] as $name) {
            $f->team->users()->attach(User::factory()->create(['name' => $name]), ['role' => 'editor']);
        }

        $read = app(TaskSentenceReader::class)->read('Sort the files and assign it to James', $f->team, Carbon::parse('2026-11-05'));

        $this->assertNull($read['assignee_id']);
        $this->assertSame('James', $read['unknown_person']);
    }

    public function test_quick_add_shows_what_it_understood_before_making_anything(): void
    {
        $f = $this->teamFixture();
        Carbon::setTestNow('2026-11-05 09:00:00');

        Livewire::actingAs($f->editor)->test(QuickAdd::class)
            ->call('show')
            ->set('sentence', 'Pay the supplier tomorrow and assign it to Admin')
            ->assertSee('Pay the supplier')
            ->assertSee('Due Fri, Nov 6')
            ->assertSee('Admin Person Fixture');

        $this->assertSame(1, Task::count());
        Carbon::setTestNow();
    }

    public function test_quick_add_goes_to_the_private_inbox_unless_told_otherwise(): void
    {
        $f = $this->teamFixture();
        Notification::fake();

        Livewire::actingAs($f->editor)->test(QuickAdd::class)
            ->call('show')
            ->set('sentence', 'Pay the supplier and assign it to Admin')
            ->call('save')
            ->assertSet('open', false)
            ->assertDispatched('task-created');

        $task = Task::where('title', 'Pay the supplier')->sole();
        $this->assertTrue($task->taskList->is_inbox);
        $this->assertSame($f->editor->id, $task->taskList->owner_id);
        $this->assertSame($f->admin->id, $task->assignee_id);
        $this->assertSame(1, $task->activities()->count());
        Notification::assertSentTo($f->admin, TaskAssignedNotification::class);
    }

    public function test_quick_add_offers_the_board_being_looked_at_first(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(QuickAdd::class, ['board' => $f->list->id])
            ->call('show')
            ->assertSet('listId', (string) $f->list->id)
            ->set('sentence', 'On the board')
            ->call('save');

        $this->assertSame($f->list->id, Task::where('title', 'On the board')->sole()->task_list_id);
    }

    public function test_quick_add_says_so_when_there_is_nothing_to_make(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(QuickAdd::class)->call('show')->set('sentence', '  ')->call('save')->assertHasErrors('sentence');
    }

    public function test_a_viewer_can_use_their_inbox_but_not_write_to_a_board(): void
    {
        $f = $this->teamFixture();

        $add = Livewire::actingAs($f->viewer)->test(QuickAdd::class, ['board' => $f->list->id])->call('show');
        $inbox = TaskList::where('is_inbox', true)->where('owner_id', $f->viewer->id)->sole();
        $add->assertSet('listId', (string) $inbox->id);

        $add->set('sentence', 'Mine')->call('save');
        $this->assertSame($inbox->id, Task::where('title', 'Mine')->sole()->task_list_id);

        $add->call('show')->set('sentence', 'Not mine')->set('listId', (string) $f->list->id)->call('save')->assertForbidden();
        $this->assertSame(0, Task::where('title', 'Not mine')->count());
    }

    public function test_nobody_can_add_to_someone_elses_inbox(): void
    {
        $f = $this->teamFixture();
        $theirs = app(InboxLists::class)->for($f->editor, $f->team);

        Livewire::actingAs($f->owner)->test(QuickAdd::class)
            ->call('show')->set('sentence', 'Snooping')->set('listId', (string) $theirs->id)->call('save')->assertForbidden();

        $this->assertSame(0, Task::where('title', 'Snooping')->count());
    }

    public function test_the_inbox_route_makes_one_inbox_and_keeps_it(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->editor)->get(route('tasks.inbox'))->assertRedirect();
        $this->actingAs($f->editor)->get(route('tasks.inbox'))->assertRedirect();

        $this->assertSame(1, TaskList::where('is_inbox', true)->where('owner_id', $f->editor->id)->count());
    }

    public function test_an_inbox_is_private_even_from_the_owner_of_the_team(): void
    {
        $f = $this->teamFixture();
        $inbox = app(InboxLists::class)->for($f->editor, $f->team);
        $task = $inbox->tasks()->create(['title' => 'Private thought', 'status' => 'todo']);

        $this->actingAs($f->editor)->get(route('task-lists.show', $inbox))->assertOk();
        $this->actingAs($f->owner)->get(route('task-lists.show', $inbox))->assertForbidden();

        Livewire::actingAs($f->owner)->test(TaskDetail::class)->call('openTask', $task->id)->assertForbidden();
        Livewire::actingAs($f->owner)->test(FindTasks::class)->set('query', 'Private')->assertDontSee('Private thought');
        Livewire::actingAs($f->owner)->test(SmartViews::class, ['view' => 'upcoming'])->assertDontSee('Private thought');
        $this->actingAs($f->owner)->get(route('dashboard'))->assertDontSee('Private thought');
    }

    public function test_an_inbox_is_not_listed_as_a_board(): void
    {
        $f = $this->teamFixture();
        $inbox = app(InboxLists::class)->for($f->owner, $f->team);

        $this->assertFalse($f->team->boards()->pluck('id')->contains($inbox->id));
        $this->assertFalse($f->owner->can('archive', $inbox));
    }

    public function test_find_searches_the_team_and_goes_no_further(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Quarterly return', 'status' => 'todo']);
        $theirs = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Elsewhere']);
        $theirs->tasks()->create(['title' => 'Quarterly secret', 'status' => 'todo']);

        Livewire::actingAs($f->editor)->test(FindTasks::class)
            ->call('show')
            ->set('query', 'Quarterly')
            ->assertSee('Quarterly return')
            ->assertSee('Fixture board')
            ->assertDontSee('Quarterly secret');
    }

    public function test_find_says_when_nothing_matches(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(FindTasks::class)->call('show')->set('query', 'Nothing like this')->assertSee('Nothing matches that.');
    }

    public function test_a_card_can_be_added_at_the_foot_of_a_column(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Before', 'status' => 'review', 'sort_order' => 4]);

        Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('addHere', 'review', '  Second look  ')
            ->assertSee('Second look');

        $task = Task::where('title', 'Second look')->sole();
        $this->assertSame('review', $task->status);
        $this->assertSame(5, $task->sort_order);
        $this->assertSame($f->editor->id, $task->creator_id);
        $this->assertSame(1, $task->activities()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function badCards(): array
    {
        return [
            'nothing typed' => ['todo', '   '],
            'no such column' => ['someday', 'A title'],
            'straight to done would skip finishing' => ['done', 'A title'],
        ];
    }

    #[DataProvider('badCards')]
    public function test_a_card_that_makes_no_sense_is_refused(string $status, string $title): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->call('addHere', $status, $title)->assertHasErrors();

        $this->assertSame(1, Task::count());
    }

    public function test_a_viewer_cannot_add_a_card(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->viewer)->test(TaskBoard::class, ['taskList' => $f->list])->call('addHere', 'todo', 'Nope')->assertForbidden();

        $this->assertSame(1, Task::count());
    }

    public function test_the_person_is_told_where_their_new_task_went(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(QuickAdd::class)
            ->call('show')->set('sentence', 'Note this')->call('save')
            ->assertSee('Added to your Inbox.');
    }

    public function test_the_editor_refuses_a_field_it_does_not_know_at_the_service_too(): void
    {
        $f = $this->teamFixture();

        $this->expectException(ValidationException::class);
        app(TaskEditor::class)->set($f->task, 'creator_id', 1);
    }

    public function test_done_tasks_from_before_get_a_finish_time_from_their_last_change(): void
    {
        $f = $this->teamFixture();
        $migration = include database_path('migrations/2026_11_06_000001_add_task_lifecycle_fields.php');
        $migration->down();
        $done = $f->list->tasks()->create(['title' => 'Old', 'status' => 'done']);
        $open = $f->list->tasks()->create(['title' => 'Open', 'status' => 'todo']);

        $migration->up();

        $this->assertNotNull($done->fresh()->completed_at);
        $this->assertNull($open->fresh()->completed_at);
    }
}
