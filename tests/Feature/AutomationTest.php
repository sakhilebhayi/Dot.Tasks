<?php

namespace Tests\Feature;

use App\Livewire\Tasks\ManageRules;
use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskField;
use App\Models\TaskList;
use App\Models\TaskRule;
use App\Models\TaskRuleRun;
use App\Notifications\TaskEventNotification;
use App\Services\AutomationCatalog;
use App\Services\Automations;
use App\Services\InboxLists;
use App\Services\TaskEditor;
use App\Services\TaskStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §10: rules a person can read before turning on, which never go round anyone's permissions. */
class AutomationTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function rule(object $f, array $values = []): TaskRule
    {
        return TaskRule::create($values + [
            'team_id' => $f->team->id, 'created_by' => $f->admin->id, 'name' => 'Rule', 'trigger' => 'created',
            'conditions' => [], 'actions' => [['type' => 'priority', 'value' => 'urgent']], 'enabled' => true,
        ]);
    }

    private function board(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskBoard::class, ['taskList' => $f->list]);
    }

    private function page(object $f, string $who = 'admin')
    {
        return Livewire::actingAs($f->{$who})->test(ManageRules::class);
    }

    private function make(object $f, array $values = []): Task
    {
        return $f->list->tasks()->create($values + ['title' => 'New', 'status' => 'todo']);
    }

    public function test_a_rule_acts_when_its_moment_comes(): void
    {
        $f = $this->teamFixture();
        $this->rule($f);

        $task = $this->make($f);

        $this->assertSame('urgent', $task->fresh()->priority);
        $run = TaskRuleRun::sole();
        $this->assertSame('done', $run->status);
        $this->assertStringContainsString('urgent', $run->summary);
    }

    public function test_the_history_names_the_rule_and_who_it_acted_as(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['name' => 'Escalate']);

        $task = $this->make($f);

        $line = $task->activities()->sole();
        $this->assertStringStartsWith('Rule "Escalate": ', $line->summary);
        $this->assertSame($f->admin->id, $line->user_id);
    }

    public function test_a_rule_that_is_off_does_nothing(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['enabled' => false]);

        $this->assertSame('medium', $this->make($f)->fresh()->priority);
        $this->assertSame(0, TaskRuleRun::count());
    }

    public function test_a_rule_keeps_to_its_board_and_its_team(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Other']);
        $this->rule($f, ['task_list_id' => $other->id]);
        $this->rule($f, ['team_id' => $f->outsider->currentTeam->id]);

        $this->assertSame('medium', $this->make($f)->fresh()->priority);
        $this->assertSame('urgent', $other->tasks()->create(['title' => 'There', 'status' => 'todo'])->fresh()->priority);
    }

    public function test_no_rule_touches_a_private_inbox(): void
    {
        $f = $this->teamFixture();
        $this->rule($f);

        $task = app(InboxLists::class)->for($f->editor, $f->team)->tasks()->create(['title' => 'Mine', 'status' => 'todo']);

        $this->assertSame('medium', $task->fresh()->priority);
        $this->assertSame(0, TaskRuleRun::count());
    }

    /** @return array<string, array{list<array<string, string>>, string, bool}> */
    public static function conditions(): array
    {
        return [
            'all hold' => [[['field' => 'title', 'op' => 'contains', 'value' => 'pump'], ['field' => 'priority', 'op' => 'is', 'value' => 'high']], 'all', true],
            'one fails with all' => [[['field' => 'title', 'op' => 'contains', 'value' => 'pump'], ['field' => 'priority', 'op' => 'is', 'value' => 'low']], 'all', false],
            'one holds with any' => [[['field' => 'title', 'op' => 'contains', 'value' => 'pump'], ['field' => 'priority', 'op' => 'is', 'value' => 'low']], 'any', true],
            'none holds with any' => [[['field' => 'title', 'op' => 'contains', 'value' => 'crusher'], ['field' => 'priority', 'op' => 'is', 'value' => 'low']], 'any', false],
            'is not' => [[['field' => 'priority', 'op' => 'is_not', 'value' => 'low']], 'all', true],
            'nobody on it' => [[['field' => 'assignee', 'op' => 'empty', 'value' => '']], 'all', true],
            'someone on it' => [[['field' => 'assignee', 'op' => 'filled', 'value' => '']], 'all', false],
            'matches whatever the case' => [[['field' => 'title', 'op' => 'contains', 'value' => 'PUMP']], 'all', true],
        ];
    }

    #[DataProvider('conditions')]
    public function test_conditions_decide_whether_a_rule_fires(array $conditions, string $match, bool $fires): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f, ['conditions' => $conditions, 'match' => $match, 'enabled' => false]);

        $this->assertSame($fires, app(Automations::class)->fits($rule, $this->make($f, ['title' => 'Fix the pump', 'priority' => 'high'])));
    }

    public function test_labels_and_custom_fields_are_conditions_too(): void
    {
        $f = $this->teamFixture();
        $field = TaskField::create(['team_id' => $f->team->id, 'name' => 'Site', 'type' => 'text']);
        $task = $this->make($f);
        $task->labels()->attach($f->label);
        $task->fieldValues()->create(['task_field_id' => $field->id, 'value' => 'Shaft 4']);
        $automations = app(Automations::class);

        $this->assertTrue($automations->fits($this->rule($f, ['enabled' => false, 'conditions' => [['field' => 'label', 'op' => 'is', 'value' => (string) $f->label->id]]]), $task));
        $this->assertFalse($automations->fits($this->rule($f, ['enabled' => false, 'conditions' => [['field' => 'label', 'op' => 'is_not', 'value' => (string) $f->label->id]]]), $task));
        $this->assertTrue($automations->fits($this->rule($f, ['enabled' => false, 'conditions' => [['field' => 'f'.$field->id, 'op' => 'is', 'value' => 'shaft 4']]]), $task));
    }

    /** @return array<string, array{string, string, string}> */
    public static function actions(): array
    {
        return [
            'priority' => ['priority', 'high', 'priority'],
            'move' => ['move', 'review', 'status'],
        ];
    }

    #[DataProvider('actions')]
    public function test_actions_change_the_task(string $type, string $value, string $column): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['actions' => [['type' => $type, 'value' => $value]]]);

        $this->assertSame($value, $this->make($f)->fresh()->{$column});
    }

    public function test_every_other_action_does_its_work(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $f = $this->teamFixture();
        Notification::fake();
        $this->rule($f, ['actions' => [
            ['type' => 'assign', 'value' => (string) $f->editor->id],
            ['type' => 'label', 'value' => (string) $f->label->id],
            ['type' => 'due', 'value' => '3'],
            ['type' => 'comment', 'value' => 'Set up by a rule'],
            ['type' => 'notify', 'value' => 'assignee'],
            ['type' => 'approval', 'value' => (string) $f->owner->id],
        ]]);

        $task = $this->make($f)->fresh();

        $this->assertSame($f->editor->id, $task->assignee_id);
        $this->assertSame(1, $task->labels()->count());
        $this->assertSame('2026-11-08', $task->due_date->toDateString());
        $this->assertSame('Set up by a rule', $task->comments()->sole()->body);
        $this->assertSame($f->owner->id, $task->approvals()->sole()->approver_id);
        Notification::assertSentTo($f->editor, TaskEventNotification::class, fn ($n) => $n->event === 'automation');
    }

    public function test_moved_to_the_next_column_means_the_next_one_along(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['actions' => [['type' => 'move', 'value' => 'next']]]);

        $this->assertSame('in_progress', $this->make($f)->fresh()->status);
    }

    public function test_finishing_every_subtask_can_move_the_parent(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => 'subtasks_done', 'actions' => [['type' => 'move', 'value' => 'review']]]);
        $a = $this->make($f, ['title' => 'A', 'parent_id' => $f->task->id]);
        $b = $this->make($f, ['title' => 'B', 'parent_id' => $f->task->id]);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('toggleSubtask', $a->id);
        $this->assertSame('todo', $f->task->fresh()->status);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('toggleSubtask', $b->id);
        $this->assertSame('review', $f->task->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function moments(): array
    {
        return ['moved' => ['moved'], 'finished' => ['completed']];
    }

    #[DataProvider('moments')]
    public function test_moving_and_finishing_set_off_their_rules(string $trigger): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => $trigger, 'actions' => [['type' => 'comment', 'value' => 'Noted']]]);

        $this->board($f)->call('place', $f->task->id, $trigger === 'moved' ? 'review' : 'done');

        $this->assertSame(1, $f->task->comments()->count());
    }

    public function test_a_task_becoming_blocked_and_freed_sets_off_rules(): void
    {
        $f = $this->teamFixture();
        $first = $this->make($f, ['title' => 'First']);
        $this->rule($f, ['trigger' => 'blocked', 'actions' => [['type' => 'priority', 'value' => 'high']]]);
        $this->rule($f, ['trigger' => 'unblocked', 'actions' => [['type' => 'priority', 'value' => 'low']]]);

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('addBlocker', $first->id);
        $this->assertSame('high', $f->task->fresh()->priority);

        $this->board($f)->call('place', $first->id, 'done');
        $this->assertSame('low', $f->task->fresh()->priority);
    }

    public function test_being_given_to_someone_sets_off_a_rule(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => 'assigned', 'actions' => [['type' => 'priority', 'value' => 'high']]]);

        app(TaskEditor::class)->assign($f->task, $f->editor, $f->owner);

        $this->assertSame('high', $f->task->fresh()->priority);
    }

    public function test_overdue_fires_once_per_due_date_and_again_if_the_date_moves(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $f = $this->teamFixture();
        $f->task->update(['due_date' => '2026-11-01']);
        $this->rule($f, ['trigger' => 'overdue', 'actions' => [['type' => 'comment', 'value' => 'Late']]]);
        $automations = app(Automations::class);

        $automations->sweep();
        $automations->sweep();
        $this->assertSame(1, $f->task->comments()->count());

        $f->task->update(['due_date' => '2026-11-02']);
        $automations->sweep();
        $this->assertSame(2, $f->task->comments()->count());
    }

    public function test_overdue_goes_by_the_teams_clock(): void
    {
        Carbon::setTestNow('2026-11-05 23:30:00');
        $f = $this->teamFixture();
        $f->team->forceFill(['timezone' => 'Africa/Johannesburg'])->save();
        $f->task->update(['due_date' => '2026-11-05']);
        $this->rule($f, ['trigger' => 'overdue', 'actions' => [['type' => 'comment', 'value' => 'Late']]]);

        app(Automations::class)->sweep();

        $this->assertSame(1, $f->task->comments()->count());
    }

    public function test_a_delay_waits_and_the_sweep_runs_it_once(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $f = $this->teamFixture();
        $this->rule($f, ['delay_minutes' => 60]);
        $task = $this->make($f);

        $this->assertSame('medium', $task->fresh()->priority);
        $this->assertSame('waiting', TaskRuleRun::sole()->status);

        $this->artisan('tasks:run-rules')->assertSuccessful();
        $this->assertSame('medium', $task->fresh()->priority);

        Carbon::setTestNow('2026-11-05 10:01:00');
        $this->artisan('tasks:run-rules')->assertSuccessful();
        $this->assertSame('urgent', $task->fresh()->priority);
        $this->assertSame('done', TaskRuleRun::sole()->status);
    }

    public function test_the_same_event_runs_a_rule_only_once(): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f, ['trigger' => 'completed', 'actions' => [['type' => 'comment', 'value' => 'Once']]]);
        $automations = app(Automations::class);

        $automations->fire('completed', $f->task, 'same');
        $automations->fire('completed', $f->task, 'same');

        $this->assertSame(1, $f->task->comments()->count());
        $this->assertSame(1, $rule->runs()->count());
    }

    public function test_two_rules_cannot_set_each_other_off_for_ever(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => 'moved', 'name' => 'Forward', 'actions' => [['type' => 'move', 'value' => 'next']]]);
        $this->rule($f, ['trigger' => 'moved', 'name' => 'Back', 'actions' => [['type' => 'move', 'value' => 'todo']]]);

        $this->board($f)->call('place', $f->task->id, 'in_progress')->assertReturned(['ok' => true]);

        $this->assertLessThan(10, TaskRuleRun::count());
    }

    public function test_a_rule_does_not_set_itself_off(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => 'moved', 'actions' => [['type' => 'move', 'value' => 'next']]]);

        $this->board($f)->call('place', $f->task->id, 'in_progress');

        $this->assertSame('review', $f->task->fresh()->status, 'one step, not to the end of the board');
    }

    public function test_a_chain_of_rules_stops_three_deep(): void
    {
        $f = $this->teamFixture();
        foreach (['One', 'Two', 'Three', 'Four'] as $name) {
            $this->rule($f, ['trigger' => 'moved', 'name' => $name, 'actions' => [['type' => 'priority', 'value' => 'high']]]);
        }
        $rule = app(Automations::class);

        $chain = fn (int $depth) => (new \ReflectionClass(Automations::class))->setStaticPropertyValue('running', range(1000, 999 + $depth));

        $chain(3);
        $rule->fire('moved', $f->task, 'deep');
        $chain(0);
        $this->assertSame(0, TaskRuleRun::count());

        $rule->fire('moved', $f->task, 'shallow');
        $this->assertSame(4, TaskRuleRun::count());
    }

    public function test_a_rule_cannot_finish_a_blocked_task(): void
    {
        $f = $this->teamFixture();
        $f->task->blockedBy()->attach($this->make($f, ['title' => 'First']));
        $rule = $this->rule($f, ['trigger' => 'moved', 'actions' => [['type' => 'move', 'value' => 'done']]]);

        $this->board($f)->call('place', $f->task->id, 'review');

        $this->assertSame('review', $f->task->fresh()->status);
        $run = $rule->runs()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Blocked by', $run->error);
    }

    public function test_a_failed_rule_tells_its_author_and_changes_nothing(): void
    {
        $f = $this->teamFixture();
        Notification::fake();
        $f->task->blockedBy()->attach($this->make($f, ['title' => 'First']));
        $this->rule($f, ['trigger' => 'moved', 'actions' => [['type' => 'priority', 'value' => 'urgent'], ['type' => 'move', 'value' => 'done']]]);

        $this->board($f)->call('place', $f->task->id, 'review');

        $this->assertSame('medium', $f->task->fresh()->priority, 'all the actions or none of them');
        Notification::assertSentTo($f->admin, TaskEventNotification::class, fn ($n) => $n->event === 'automation' && str_contains($n->message, 'did not run'));
    }

    public function test_a_rule_can_only_do_what_its_author_can(): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f, ['created_by' => $f->viewer->id]);

        $task = $this->make($f);

        $this->assertSame('medium', $task->fresh()->priority);
        $this->assertSame('failed', $rule->runs()->sole()->status);
    }

    public function test_a_rule_whose_author_left_stops(): void
    {
        $f = $this->teamFixture();
        $f->team->removeUser($f->admin);
        $rule = $this->rule($f);

        $this->make($f);

        $this->assertStringContainsString('no longer on the team', $rule->runs()->sole()->error);
    }

    public function test_the_signed_in_person_comes_back_after_a_rule_runs(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => 'moved']);

        $this->actingAs($f->editor);
        app(TaskStatusService::class)->move($f->task, 'review');

        $this->assertSame($f->editor->id, auth()->id());
    }

    public function test_a_trouble_tries_again_and_then_gives_up_and_a_person_can_retry(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $f = $this->teamFixture();
        $rule = $this->rule($f, ['actions' => [['type' => 'label', 'value' => '999999']]]);
        $this->make($f);
        $run = $rule->runs()->sole();

        $this->assertSame('waiting', $run->status);
        $this->assertSame(1, $run->attempts);
        $this->assertTrue($run->run_at->gt(now()));

        Carbon::setTestNow('2026-11-05 09:06:00');
        app(Automations::class)->sweep();
        Carbon::setTestNow('2026-11-05 09:17:00');
        app(Automations::class)->sweep();

        $this->assertSame('failed', $run->fresh()->status);

        $rule->update(['actions' => [['type' => 'priority', 'value' => 'high']]]);
        $this->page($f)->call('retryRun', $run->id);
        $this->assertSame('done', $run->fresh()->status);
    }

    public function test_a_run_that_needs_approval_waits_for_an_admin(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['needs_approval' => true]);
        $task = $this->make($f);
        $run = TaskRuleRun::sole();

        $this->assertSame('needs_approval', $run->status);
        $this->assertSame('medium', $task->fresh()->priority);

        $this->page($f, 'editor')->call('approveRun', $run->id)->assertForbidden();
        $this->page($f)->call('approveRun', $run->id);

        $this->assertSame('urgent', $task->fresh()->priority);
        $this->assertSame($f->admin->id, $run->fresh()->decided_by);
    }

    public function test_a_declined_run_never_happens(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['needs_approval' => true]);
        $task = $this->make($f);

        $this->page($f)->call('declineRun', TaskRuleRun::sole()->id);
        app(Automations::class)->sweep();

        $this->assertSame('medium', $task->fresh()->priority);
        $this->assertSame('declined', TaskRuleRun::sole()->status);
    }

    public function test_a_run_that_was_settled_cannot_be_decided_again(): void
    {
        $f = $this->teamFixture();
        $this->rule($f);
        $this->make($f);
        $run = TaskRuleRun::sole();

        $this->page($f)->call('declineRun', $run->id)->assertStatus(409);
        $this->page($f)->call('approveRun', $run->id)->assertStatus(409);
        $this->page($f)->call('retryRun', $run->id)->assertStatus(409);

        $this->assertSame('done', $run->fresh()->status);
    }

    public function test_an_admin_writes_a_rule_and_it_starts_off(): void
    {
        $f = $this->teamFixture();

        $this->page($f)->call('start')
            ->set('name', 'Urgent safety')->set('trigger', 'created')->set('board', (string) $f->list->id)
            ->call('addCondition')->set('conditions.0.value', 'urgent')
            ->set('actions.0.type', 'assign')->set('actions.0.value', (string) $f->editor->id)
            ->assertSee('When a task is created on Fixture board, if priority is urgent, then give it to Editor Person Fixture.')
            ->call('save')->assertHasNoErrors();

        $rule = TaskRule::sole();
        $this->assertFalse($rule->enabled);
        $this->assertSame($f->admin->id, $rule->created_by);
    }

    public function test_the_sentence_says_all_of_it(): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f, [
            'trigger' => 'overdue', 'match' => 'any', 'delay_minutes' => 120, 'needs_approval' => true,
            'conditions' => [['field' => 'priority', 'op' => 'is', 'value' => 'high'], ['field' => 'assignee', 'op' => 'empty', 'value' => '']],
            'actions' => [['type' => 'notify', 'value' => 'creator'], ['type' => 'move', 'value' => 'next']],
        ]);

        $this->assertSame(
            'When a task becomes overdue, if priority is high or person is empty, wait 2 hours, ask an admin to approve, then notify the person who made it and move it to the next column.',
            app(AutomationCatalog::class)->describe($rule),
        );
    }

    public function test_before_turning_on_a_person_sees_what_a_rule_would_take(): void
    {
        $f = $this->teamFixture();
        $this->make($f, ['title' => 'Pump', 'priority' => 'high']);
        $this->rule($f, ['enabled' => false, 'conditions' => [['field' => 'priority', 'op' => 'is', 'value' => 'high']]]);

        $this->page($f)->assertSee('1 open task fit it today.');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badRules(): array
    {
        return [
            'no name' => [['name' => '']],
            'no trigger' => [['trigger' => 'whenever']],
            'no actions' => [['actions' => []]],
            'an empty condition' => [['conditions' => [['field' => 'priority', 'op' => 'is', 'value' => '']]]],
            'an unknown field' => [['conditions' => [['field' => 'password', 'op' => 'is', 'value' => 'x']]]],
            'an unknown action' => [['actions' => [['type' => 'delete', 'value' => 'x']]]],
            'a negative delay' => [['delay' => -1]],
            'an action with no value' => [['actions' => [['type' => 'priority', 'value' => '']]]],
        ];
    }

    #[DataProvider('badRules')]
    public function test_a_rule_that_makes_no_sense_is_refused(array $change): void
    {
        $f = $this->teamFixture();

        $this->expectException(ValidationException::class);
        app(AutomationCatalog::class)->clean($change + ['name' => 'A', 'board' => '', 'trigger' => 'created', 'match' => 'all', 'conditions' => [], 'actions' => [['type' => 'priority', 'value' => 'high']], 'delay' => 0, 'approval' => false], $f->team);
    }

    public function test_a_rule_cannot_name_a_stranger_or_another_teams_board_or_label(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Theirs']);

        foreach ([
            ['actions' => [['type' => 'assign', 'value' => (string) $f->outsider->id]]],
            ['actions' => [['type' => 'notify', 'value' => (string) $f->outsider->id]]],
            ['actions' => [['type' => 'label', 'value' => '999999']]],
            ['board' => (string) $theirs->id, 'actions' => [['type' => 'priority', 'value' => 'high']]],
        ] as $change) {
            $this->page($f)->call('start')->set('name', 'A')->set('actions', $change['actions'])->set('board', $change['board'] ?? '')->call('save')->assertHasErrors('rule');
        }

        $this->assertSame(0, TaskRule::count());
    }

    public function test_editing_a_rule_makes_it_act_as_the_person_who_edited_it(): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f);

        $this->page($f)->call('start', $rule->id)->assertSet('name', 'Rule')->set('name', 'Renamed')->call('save');
        $this->assertSame('Renamed', $rule->fresh()->name);

        Livewire::actingAs($f->owner)->test(ManageRules::class)->call('start', $rule->id)->call('save');
        $this->assertSame($f->owner->id, $rule->fresh()->created_by);
    }

    public function test_everyone_reads_the_rules_and_only_admins_change_them(): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f, ['name' => 'Readable']);

        $this->actingAs($f->viewer)->get(route('tasks.automation'))->assertOk()->assertSee('Readable');

        foreach (['start', 'toggle', 'remove'] as $method) {
            $this->page($f, 'editor')->call($method, $rule->id)->assertForbidden();
        }
        $this->page($f, 'editor')->assertDontSee('New rule');

        $this->assertTrue($rule->fresh()->enabled);
    }

    public function test_a_rule_is_turned_on_off_and_deleted(): void
    {
        $f = $this->teamFixture();
        $rule = $this->rule($f, ['enabled' => false]);

        $this->page($f)->call('toggle', $rule->id);
        $this->assertTrue($rule->fresh()->enabled);

        $this->page($f)->call('remove', $rule->id);
        $this->assertNull($rule->fresh());
    }

    public function test_another_teams_rule_and_runs_stay_out_of_reach(): void
    {
        $f = $this->teamFixture();
        $theirs = TaskList::create(['team_id' => $f->outsider->currentTeam->id, 'owner_id' => $f->outsider->id, 'name' => 'Theirs']);
        $rule = $this->rule($f, ['team_id' => $f->outsider->currentTeam->id, 'needs_approval' => true, 'name' => 'Their rule']);
        $run = TaskRuleRun::create(['task_rule_id' => $rule->id, 'task_id' => $theirs->tasks()->create(['title' => 'T', 'status' => 'todo'])->id, 'event_key' => 'x', 'status' => 'needs_approval', 'run_at' => now()]);

        $this->page($f)->assertDontSee('Their rule')->call('toggle', $rule->id)->assertNotFound();
        $this->page($f)->call('approveRun', $run->id)->assertNotFound();
    }

    public function test_someone_is_asked_to_approve_and_the_task_waits_for_them(): void
    {
        $f = $this->teamFixture();
        Notification::fake();

        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('askForApproval', $f->admin->id);
        Notification::assertSentTo($f->admin, TaskEventNotification::class, fn ($n) => $n->event === 'approval');

        $this->board($f)->call('place', $f->task->id, 'done')->assertReturned(['ok' => false, 'message' => 'Waiting for approval from Admin Person Fixture.']);

        Livewire::actingAs($f->admin)->test(TaskDetail::class)->call('openTask', $f->task->id)
            ->call('answerApproval', TaskApproval::sole()->id, true, 'Looks right');

        $this->assertSame('approved', TaskApproval::sole()->status);
        $this->board($f)->call('place', $f->task->id, 'done')->assertReturned(['ok' => true]);
    }

    public function test_only_the_person_asked_can_answer(): void
    {
        $f = $this->teamFixture();
        $approval = $f->task->approvals()->create(['approver_id' => $f->admin->id, 'requested_by' => $f->editor->id]);

        Livewire::actingAs($f->owner)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('answerApproval', $approval->id, true)->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_nobody_approves_their_own_request_or_asks_a_stranger(): void
    {
        $f = $this->teamFixture();
        $panel = fn () => Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id);

        $panel()->call('askForApproval', $f->editor->id)->assertHasErrors('approval');
        $panel()->call('askForApproval', $f->outsider->id)->assertForbidden();
    }

    public function test_a_declined_approval_lets_work_go_on_and_says_so(): void
    {
        $f = $this->teamFixture();
        $approval = $f->task->approvals()->create(['approver_id' => $f->admin->id, 'requested_by' => $f->editor->id]);

        Livewire::actingAs($f->admin)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('answerApproval', $approval->id, false, 'Not yet');

        $this->assertSame('Not yet', $approval->fresh()->note);
        $this->assertStringContainsString('Declined it: Not yet', $f->task->activities()->first()->summary);
    }

    public function test_being_approved_can_move_a_task_to_the_next_stage(): void
    {
        $f = $this->teamFixture();
        $this->rule($f, ['trigger' => 'approved', 'actions' => [['type' => 'move', 'value' => 'next']]]);
        $approval = $f->task->approvals()->create(['approver_id' => $f->owner->id, 'requested_by' => $f->editor->id]);

        Livewire::actingAs($f->owner)->test(TaskDetail::class)->call('openTask', $f->task->id)->call('answerApproval', $approval->id, true);

        $this->assertSame('in_progress', $f->task->fresh()->status);
    }

    public function test_my_day_shows_what_is_waiting_for_me_to_approve(): void
    {
        $f = $this->teamFixture();
        $f->task->approvals()->create(['approver_id' => $f->admin->id, 'requested_by' => $f->editor->id]);

        $this->assertSame(['Fixture task'], Livewire::actingAs($f->admin)->test(MyDay::class)->instance()->toApprove->pluck('title')->all());
        $this->assertSame([], Livewire::actingAs($f->editor)->test(MyDay::class)->instance()->toApprove->pluck('title')->all());
    }
}
