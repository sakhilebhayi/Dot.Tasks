<?php

namespace Tests\Feature;

use App\Livewire\Tasks\RemovedTasks;
use App\Models\RecordRestoration;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskEscalationProposal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\Concerns\RemovesTasks;
use Tests\TestCase;

/**
 * tasks:purge-removed hard-deletes tasks that have been deleted for longer than
 * the retention window (REMOVED_RETENTION_DAYS, default 90), subtasks with their
 * parent, and nothing that is still live.
 */
class PurgeRemovedTasksCommandTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;
    use RemovesTasks;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Make these tasks look deleted $days ago. */
    private function removedAgo(int $days, Task ...$tasks): void
    {
        foreach ($tasks as $task) {
            Task::withoutGlobalScopes()->whereKey($task->id)->update(['deleted_at' => now()->subDays($days)]);
        }
    }

    public function test_the_default_retention_is_ninety_days(): void
    {
        $this->assertSame(90, config('tasks.removed_retention_days'));
    }

    public function test_it_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'tasks:purge-removed'));

        $this->assertNotNull($event, 'tasks:purge-removed is not scheduled');
        $this->assertSame('10 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_task_deleted_longer_ago_than_the_window_is_purged_and_a_newer_one_is_not(): void
    {
        $f = $this->teamFixture();
        $old = $f->list->tasks()->create(['title' => 'Old removal', 'status' => 'todo']);
        $recent = $f->list->tasks()->create(['title' => 'Recent removal', 'status' => 'todo']);
        $this->remove($old);
        $this->remove($recent);
        $this->removedAgo(91, $old);
        $this->removedAgo(89, $recent);

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertDatabaseMissing('tasks', ['id' => $old->id]);
        $this->assertDatabaseHas('tasks', ['id' => $recent->id]);
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id, 'deleted_at' => null]);
    }

    public function test_the_boundary_is_strictly_older_than_the_window(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $this->removedAgo(90, $f->task);

        $this->artisan('tasks:purge-removed')->assertSuccessful();
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);

        Carbon::setTestNow(now()->addSecond());
        $this->artisan('tasks:purge-removed')->assertSuccessful();
        $this->assertDatabaseMissing('tasks', ['id' => $f->task->id]);
    }

    public function test_live_and_archived_tasks_are_never_touched_whatever_their_age(): void
    {
        $f = $this->teamFixture();
        $archived = $f->list->tasks()->create(['title' => 'Archived long ago', 'status' => 'todo']);
        $archived->archive(now()->subDays(400));
        Task::withoutGlobalScopes()->whereKey($f->task->id)->update(['created_at' => now()->subDays(900), 'updated_at' => now()->subDays(900)]);
        $before = [
            Task::withoutGlobalScopes()->find($f->task->id)->toArray(),
            Task::withoutGlobalScopes()->find($archived->id)->toArray(),
        ];

        $this->artisan('tasks:purge-removed')->expectsOutputToContain('Nothing to purge')->assertSuccessful();

        $this->assertSame($before, [
            Task::withoutGlobalScopes()->find($f->task->id)->toArray(),
            Task::withoutGlobalScopes()->find($archived->id)->toArray(),
        ]);
    }

    public function test_an_expired_parent_goes_with_all_its_removed_subtasks_whatever_their_age(): void
    {
        $f = $this->teamFixture();
        $child = $this->subtask($f, $f->task, 'Child');
        $grandchild = $this->subtask($f, $child, 'Grandchild');
        $this->remove($f->task);
        $this->removedAgo(120, $f->task);
        $this->removedAgo(5, $child, $grandchild);

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertSame(0, DB::table('tasks')->whereIn('id', [$f->task->id, $child->id, $grandchild->id])->count());
    }

    public function test_an_expired_parent_with_a_live_subtask_is_kept_and_the_live_subtask_untouched(): void
    {
        $f = $this->teamFixture();
        $dead = $this->subtask($f, $f->task, 'Removed child');
        $live = $this->subtask($f, $f->task, 'Live child');
        $this->remove($f->task);
        Task::withoutGlobalScopes()->whereKey($live->id)->update(['deleted_at' => null]);
        $this->removedAgo(120, $f->task, $dead);
        $before = Task::withoutGlobalScopes()->find($live->id)->toArray();

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);
        $this->assertSame($before, Task::withoutGlobalScopes()->find($live->id)->toArray());
        // The fully removed sibling has nothing live beneath it, so it is purged on its own.
        $this->assertDatabaseMissing('tasks', ['id' => $dead->id]);
    }

    public function test_an_expired_subtask_is_purged_without_its_live_parent(): void
    {
        $f = $this->teamFixture();
        $child = $this->subtask($f, $f->task, 'Old removed child');
        $this->remove($child);
        $this->removedAgo(200, $child);

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertDatabaseMissing('tasks', ['id' => $child->id]);
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id, 'deleted_at' => null]);
    }

    public function test_what_hangs_off_a_purged_task_goes_with_it_and_other_rows_stay(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Another live task', 'status' => 'todo']);
        TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->editor->id, 'body' => 'Comment on the doomed task']);
        TaskComment::create(['task_id' => $other->id, 'user_id' => $f->editor->id, 'body' => 'Comment on the live task']);
        $f->task->labels()->attach($f->label->id);
        TaskEscalationProposal::create(['task_id' => $f->task->id, 'status' => 'pending', 'reason' => 'x']);
        DB::table('task_dependencies')->insert(['task_id' => $other->id, 'blocked_by_id' => $f->task->id]);
        $this->remove($f->task);
        $this->removedAgo(120, $f->task);
        $liveBefore = Task::withoutGlobalScopes()->find($other->id)->toArray();

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertSame(1, DB::table('task_comments')->count());
        $this->assertDatabaseHas('task_comments', ['task_id' => $other->id]);
        $this->assertSame(0, DB::table('task_label')->count());
        $this->assertSame(0, DB::table('task_escalation_proposals')->count());
        $this->assertSame(0, DB::table('task_dependencies')->count());
        $this->assertDatabaseHas('labels', ['id' => $f->label->id]);
        $this->assertSame($liveBefore, Task::withoutGlobalScopes()->find($other->id)->toArray());
    }

    public function test_a_removed_task_that_is_still_the_series_head_of_a_live_task_is_kept(): void
    {
        $f = $this->teamFixture();
        $head = $f->task;
        $head->update(['recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date']);
        $occurrence = $f->list->tasks()->create(['title' => 'Later occurrence', 'status' => 'todo', 'recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date', 'recurrence_parent_id' => $head->id]);
        $this->remove($head);
        $this->removedAgo(120, $head);

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertDatabaseHas('tasks', ['id' => $head->id]);
        $this->assertSame($head->id, Task::withoutGlobalScopes()->find($occurrence->id)->recurrence_parent_id, 'the live task keeps its series link');

        $this->remove($occurrence);
        $this->removedAgo(120, $occurrence);
        $this->artisan('tasks:purge-removed')->assertSuccessful();
        $this->assertDatabaseMissing('tasks', ['id' => $head->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $occurrence->id]);
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $f = $this->teamFixture();
        $this->subtask($f, $f->task, 'Dry run child');
        $this->remove($f->task);
        $this->removedAgo(120, $f->task);
        $before = $this->fingerprint();

        $this->artisan('tasks:purge-removed', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run, nothing deleted. Would purge 2 tasks')
            ->assertSuccessful();

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_it_is_idempotent(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $this->removedAgo(120, $f->task);

        $this->artisan('tasks:purge-removed')->expectsOutputToContain('Purged 1 task')->assertSuccessful();
        $after = $this->fingerprint();
        $this->artisan('tasks:purge-removed')->expectsOutputToContain('Nothing to purge')->assertSuccessful();

        $this->assertSame($after, $this->fingerprint());
    }

    public function test_it_logs_a_summary_with_the_counts_and_cutoff(): void
    {
        $f = $this->teamFixture();
        TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->editor->id, 'body' => 'Counted comment']);
        $this->remove($f->task);
        $this->removedAgo(120, $f->task);

        Log::shouldReceive('info')->once()->with('tasks.purge-removed', \Mockery::on(
            fn (array $c) => $c['dry_run'] === false
                && $c['retention_days'] === 90
                && $c['cutoff'] === '2026-07-20T12:00:00+00:00'
                && $c['purged'] === ['tasks' => 1, 'comments' => 1]
                && $c['kept'] === ['live_subtasks' => 0, 'series_heads' => 0],
        ));

        $this->artisan('tasks:purge-removed')->assertSuccessful();
    }

    public function test_the_retention_window_is_configurable_and_a_nonsense_value_is_refused(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $this->removedAgo(40, $f->task);

        $this->artisan('tasks:purge-removed')->assertSuccessful();
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);

        foreach ([0, -5] as $bad) {
            config(['tasks.removed_retention_days' => $bad]);
            $this->artisan('tasks:purge-removed')->assertFailed();
        }
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);

        config(['tasks.removed_retention_days' => 30]);
        $this->artisan('tasks:purge-removed')->assertSuccessful();
        $this->assertDatabaseMissing('tasks', ['id' => $f->task->id]);
    }

    public function test_restoration_audit_rows_outlive_the_purge(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        Livewire::actingAs($f->admin)->test(RemovedTasks::class)->call('restore', $f->task->id);
        $this->remove($f->task->fresh());
        $this->removedAgo(120, $f->task);

        $this->artisan('tasks:purge-removed')->assertSuccessful();

        $this->assertDatabaseMissing('tasks', ['id' => $f->task->id]);
        $this->assertSame(1, RecordRestoration::count());
    }
}
