<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Labels\LabelManager;
use App\Livewire\NotificationBell;
use App\Livewire\Tasks\ArchivedBoards;
use App\Livewire\Tasks\BoardColumns;
use App\Livewire\Tasks\EscalationQueue;
use App\Livewire\Tasks\ManageFields;
use App\Livewire\Tasks\ManageRules;
use App\Livewire\Tasks\ManageTemplates;
use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\MyTasks;
use App\Livewire\Tasks\RemovedTasks;
use App\Livewire\Tasks\SmartViews;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskCalendar;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskTable;
use App\Livewire\Tasks\TaskTimeline;
use App\Livewire\Tasks\TeamWork;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskEscalationProposal;
use App\Models\TaskField;
use App\Models\TaskList;
use App\Models\TaskRule;
use App\Models\TaskRuleRun;
use App\Models\TaskStatus;
use App\Models\TaskTemplate;
use App\Models\TaskView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * Brief §17 and acceptance test 22. Every Livewire action that takes an id, and every route with a record in it, tried
 * by an admin of one business against the records of another. The check is on the data, not the response: the
 * other business's rows must be byte for byte what they were.
 */
class TenantSweepTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private const PUBLIC_ROUTES = ['/', 'auth/ecosystem', 'cookies', 'csp-report', 'privacy-policy', 'ready', 'terms-of-service', 'up', 'up/realtime', 'up/scheduler'];

    /** Actions on a page the attacker owns, with ids that belong to the other business. */
    public static function actions(): array
    {
        $own = fn (object $t) => ['taskList' => $t->mine];
        $detail = fn (object $t, array $call) => [TaskDetail::class, [], $call, ['openTask', $t->mineTask->id]];

        return [
            'open a task' => [fn ($t) => [TaskDetail::class, [], ['openTask', $t->task->id]]],
            'complete from my tasks' => [fn ($t) => [MyTasks::class, [], ['completeTask', $t->plain->id]]],
            'complete from a view' => [fn ($t) => [SmartViews::class, [], ['completeTask', $t->plain->id]]],
            'restore removed' => [fn ($t) => [RemovedTasks::class, [], ['restore', $t->removed->id]]],
            'restore a board' => [fn ($t) => [ArchivedBoards::class, [], ['restoreBoard', $t->archived->id]]],
            'plan a day' => [fn ($t) => [MyDay::class, [], ['planIt', $t->task->id, 0]]],
            'reschedule from my day' => [fn ($t) => [MyDay::class, [], ['reschedule', $t->task->id, '2030-01-01', '']]],
            'finish from my day' => [fn ($t) => [MyDay::class, [], ['toggleDone', $t->plain->id]]],
            'reassign' => [fn ($t) => [TeamWork::class, [], ['reassign', $t->task->id, (string) $t->attacker->id, '']]],
            'place on board' => [fn ($t) => [TaskBoard::class, $own($t), ['place', $t->plain->id, 'done', 0, 'todo', '']]],
            'delete on board' => [fn ($t) => [TaskBoard::class, $own($t), ['deleteTask', $t->plain->id, true]]],
            'restore on board' => [fn ($t) => [TaskBoard::class, $own($t), ['restoreTask', $t->removed->id]]],
            'pull to my board' => [fn ($t) => [TaskBoard::class, $own($t), ['moveToBoard', $t->task->id, $t->mine->id]]],
            'push to their board' => [fn ($t) => [TaskBoard::class, $own($t), ['moveToBoard', $t->mineTask->id, $t->list->id]]],
            'delete a saved view' => [fn ($t) => [TaskBoard::class, $own($t), ['deleteView', $t->view->id]]],
            'edit a cell' => [fn ($t) => [TaskTable::class, $own($t), ['saveCell', $t->task->id, 'title', 'Stolen']]],
            'calendar move' => [fn ($t) => [TaskCalendar::class, $own($t), ['reschedule', $t->task->id, '2030-01-01', '']]],
            'timeline dates' => [fn ($t) => [TaskTimeline::class, $own($t), ['setDates', $t->task->id, '2030-01-01', '2030-01-02', '', '']]],
            'timeline schedule' => [fn ($t) => [TaskTimeline::class, $own($t), ['schedule', $t->task->id, 1]]],
            'rename a column' => [fn ($t) => [BoardColumns::class, $own($t), ['rename', $t->column->id, 'Stolen']]],
            'remove a column' => [fn ($t) => [BoardColumns::class, $own($t), ['remove', $t->column->id]]],
            'remove a field' => [fn ($t) => [ManageFields::class, [], ['remove', $t->field->id]]],
            'remove a template' => [fn ($t) => [ManageTemplates::class, [], ['remove', $t->template->id]]],
            'use a template' => [fn ($t) => [ManageTemplates::class, [], ['use', $t->template->id]]],
            'pause a rule' => [fn ($t) => [ManageRules::class, [], ['toggle', $t->rule->id]]],
            'remove a rule' => [fn ($t) => [ManageRules::class, [], ['remove', $t->rule->id]]],
            'approve a run' => [fn ($t) => [ManageRules::class, [], ['approveRun', $t->run->id]]],
            'decline a run' => [fn ($t) => [ManageRules::class, [], ['declineRun', $t->run->id]]],
            'retry a run' => [fn ($t) => [ManageRules::class, [], ['retryRun', $t->run->id]]],
            'approve an escalation' => [fn ($t) => [EscalationQueue::class, [], ['approve', $t->proposal->id]]],
            'reject an escalation' => [fn ($t) => [EscalationQueue::class, [], ['confirmReject', $t->proposal->id]]],
            'edit a label' => [fn ($t) => [LabelManager::class, [], ['startEdit', $t->label->id]]],
            'delete a label' => [fn ($t) => [LabelManager::class, [], ['delete', $t->label->id]]],
            'read a notification' => [fn ($t) => [NotificationBell::class, [], ['markAsRead', $t->notification]]],
            'link to their blocker' => [fn ($t) => $detail($t, ['addBlocker', $t->task->id])],
            'finish their subtask' => [fn ($t) => $detail($t, ['toggleSubtask', $t->subtask->id])],
            'place their subtask' => [fn ($t) => $detail($t, ['placeSubtask', $t->subtask->id, 0])],
            'add their person' => [fn ($t) => $detail($t, ['toggleCollaborator', $t->victim->id])],
            'assign their person' => [fn ($t) => $detail($t, ['assignTask', $t->victim->id])],
            'ask their person' => [fn ($t) => $detail($t, ['askForApproval', $t->victim->id])],
            'answer their approval' => [fn ($t) => $detail($t, ['answerApproval', $t->approval->id, true, ''])],
            'tick their checklist' => [fn ($t) => $detail($t, ['toggleChecklistItem', $t->item->id])],
            'remove their checklist' => [fn ($t) => $detail($t, ['removeChecklistItem', $t->item->id])],
            'promote their checklist' => [fn ($t) => $detail($t, ['promoteChecklistItem', $t->item->id])],
            'set their field' => [fn ($t) => $detail($t, ['saveCustomField', $t->field->id, 'x'])],
            'use their label' => [fn ($t) => $detail($t, ['toggleLabel', $t->label->id])],
            'delete their comment' => [fn ($t) => $detail($t, ['deleteComment', $t->comment->id])],
            'reply to their comment' => [fn ($t) => $detail($t, ['replyTo', $t->comment->id])],
            'remove their file' => [fn ($t) => $detail($t, ['removeFile', $t->file->id])],
            'file their attachment' => [fn ($t) => $detail($t, ['keepInFiles', $t->file->id])],
            'move mine to their board' => [fn ($t) => $detail($t, ['moveToBoard', $t->list->id])],
            'hand over to their person' => [fn ($t) => $detail($t, ['handOver', $t->victim->id, 'Yours'])],
        ];
    }

    /** Two businesses, with one of everything in the second. */
    private function world(): object
    {
        $f = $this->teamFixture();
        $f->attacker = $f->outsider;
        $f->victim = $f->owner;
        $f->mine = $f->attacker->currentTeam->taskLists()->create(['owner_id' => $f->attacker->id, 'name' => 'Mine']);
        $f->mineTask = $f->mine->tasks()->create(['title' => 'Mine', 'status' => 'todo']);

        // Nothing hangs off this one, so only the tenant check can stop an action on it.
        $f->plain = $f->list->tasks()->create(['title' => 'Plain', 'status' => 'todo']);
        $f->subtask = $f->list->tasks()->create(['title' => 'Sub', 'status' => 'todo', 'parent_id' => $f->task->id]);
        $f->removed = $f->list->tasks()->create(['title' => 'Removed', 'status' => 'todo']);
        $f->removed->delete();
        $f->archived = $f->team->taskLists()->create(['owner_id' => $f->victim->id, 'name' => 'Old']);
        $f->archived->archive();
        $f->column = $f->list->columns()->first();
        $f->field = TaskField::create(['team_id' => $f->team->id, 'name' => 'Cost', 'type' => 'text']);
        $f->template = TaskTemplate::create(['team_id' => $f->team->id, 'created_by' => $f->victim->id, 'name' => 'T', 'title' => 'T']);
        $f->view = TaskView::create(['user_id' => $f->victim->id, 'task_list_id' => $f->list->id, 'name' => 'V', 'settings' => ['view' => 'list']]);
        $f->rule = TaskRule::create(['team_id' => $f->team->id, 'created_by' => $f->victim->id, 'name' => 'R', 'trigger' => 'task.created', 'conditions' => [], 'actions' => [['type' => 'comment', 'value' => 'x']], 'active' => true]);
        $f->run = TaskRuleRun::create(['task_rule_id' => $f->rule->id, 'task_id' => $f->task->id, 'status' => 'waiting', 'event_key' => 'sweep', 'attempts' => 0, 'run_at' => now()]);
        $f->proposal = TaskEscalationProposal::create(['task_id' => $f->task->id, 'status' => 'pending', 'reason' => 'x']);
        $f->approval = TaskApproval::create(['task_id' => $f->task->id, 'requested_by' => $f->victim->id, 'approver_id' => $f->admin->id, 'status' => 'pending']);
        $f->item = TaskChecklistItem::create(['task_id' => $f->task->id, 'title' => 'I']);
        $f->comment = TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->victim->id, 'body' => 'C']);
        $f->file = TaskAttachment::create(['task_id' => $f->task->id, 'user_id' => $f->victim->id, 'path' => 'x', 'name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
        $f->notification = (string) \Illuminate\Support\Str::uuid();
        DB::table('notifications')->insert(['id' => $f->notification, 'type' => 'x', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $f->victim->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $f;
    }

    /** Every row in every table the second business can own, so a change anywhere is seen. */
    private function snapshot(): array
    {
        $tables = array_diff(Schema::getTableListing(), ['cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'migrations', 'users']);

        return collect($tables)->mapWithKeys(fn ($table) => [(string) $table => DB::table(str($table)->afterLast('.')->value())->get()->map(fn ($row) => (array) $row)
            ->reject(fn ($row) => in_array(($row['user_id'] ?? null), [$this->attackerId], true) && str($table)->contains('dashboard'))
            ->values()->all()])->all();
    }

    private ?int $attackerId = null;

    /** Rows that legitimately appear for the attacker's own business are removed before comparing. */
    private function theirs(array $snapshot, object $t): array
    {
        $mineLists = TaskList::query()->withoutGlobalScopes()->where('team_id', $t->attacker->currentTeam->id)->pluck('id')->all();
        $mineTasks = Task::withTrashed()->whereIn('task_list_id', $mineLists)->pluck('id')->all();

        foreach ($snapshot as $table => $rows) {
            $snapshot[$table] = array_values(array_filter($rows, function ($row) use ($table, $t, $mineLists, $mineTasks) {
                return ! (($row['team_id'] ?? null) === $t->attacker->currentTeam->id
                    || in_array($row['task_list_id'] ?? null, $mineLists, true)
                    || in_array($row['task_id'] ?? null, $mineTasks, true)
                    || (str_ends_with($table, 'task_lists') && in_array($row['id'] ?? null, $mineLists, true))
                    || (str_ends_with($table, '.teams') && ($row['id'] ?? null) === $t->attacker->currentTeam->id));
            }));
        }

        return $snapshot;
    }

    #[DataProvider('actions')]
    public function test_an_admin_of_another_business_changes_nothing(\Closure $build): void
    {
        $t = $this->world();
        $this->attackerId = $t->attacker->id;
        [$component, $params, $call, $first] = array_pad($build($t), 4, null);
        $before = $this->theirs($this->snapshot(), $t);

        try {
            $page = Livewire::actingAs($t->attacker)->test($component, $params);
            if ($first) {
                $page->call(...$first);
            }
            $page->call(...$call);
        } catch (AuthorizationException|ModelNotFoundException|HttpException) {
            // Refused by throwing: that is a pass, the rows below are what counts.
        }

        $this->assertEquals($before, $this->theirs($this->snapshot(), $t));
    }

    public function test_a_task_from_another_business_never_opens(): void
    {
        $t = $this->world();

        try {
            Livewire::actingAs($t->attacker)->test(TaskDetail::class)->call('openTask', $t->task->id)->assertDontSee('Fixture task');
        } catch (AuthorizationException|ModelNotFoundException|HttpException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_every_page_with_a_record_in_it_refuses_someone_from_another_business(): void
    {
        $t = $this->world();

        $paths = [
            route('task-lists.show', $t->list),
            route('tasks.files', [$t->task, $t->file]),
        ];
        foreach ($paths as $path) {
            $this->actingAs($t->attacker)->get($path)->assertNotFound();
        }

        $withRecord = collect(Route::getRoutes())->filter(fn ($r) => str_contains($r->uri(), '{') && in_array('GET', $r->methods()) && ! str_contains($r->uri(), 'livewire') && ! str_contains($r->uri(), 'sanctum') && ! str_contains($r->uri(), 'reset-password') && ! str_contains($r->uri(), 'storage') && ! str_contains($r->uri(), 'teams') && ! str_contains($r->uri(), 'team-invitations') && ! str_contains($r->uri(), 'verify-email') && ! str_contains($r->uri(), 'two-factor') && ! str_starts_with($r->uri(), '_'))->map->uri()->values()->all();
        $this->assertEqualsCanonicalizing(['lists/{taskList}', 'tasks/{task}/files/{attachment}'], $withRecord);
    }

    public function test_only_a_short_known_list_of_routes_works_without_signing_in(): void
    {
        $public = collect(Route::getRoutes())
            ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate:') || str_contains($m, 'AuthenticateGuardian') || $m === 'guest' || str_starts_with($m, 'guest:'))))
            ->reject(fn ($r) => str_starts_with($r->uri(), 'livewire') || str_starts_with($r->uri(), 'sanctum') || str_starts_with($r->uri(), '_') || str_starts_with($r->uri(), 'storage') || str_starts_with($r->uri(), 'broadcasting'))
            ->map->uri()->unique()->sort()->values()->all();

        $this->assertSame(self::PUBLIC_ROUTES, $public);
    }
}
