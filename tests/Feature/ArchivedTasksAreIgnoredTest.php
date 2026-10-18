<?php

namespace Tests\Feature;

use App\Console\Commands\CheckTasksDueSoon;
use App\Console\Commands\DetectEscalationCandidates;
use App\Livewire\Tasks\EscalationQueue;
use App\Livewire\Tasks\SmartViews;
use App\Models\Task;
use App\Models\TaskEscalationProposal;
use App\Notifications\TaskDueSoonNotification;
use App\Services\TaskRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * Archived and deleted tasks drop out of everything that looks at "live" work:
 * the smart views, the scheduled reminders, escalation, and recurrence.
 */
class ArchivedTasksAreIgnoredTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function ways(): array
    {
        return ['archived' => ['archive'], 'soft deleted' => ['delete']];
    }

    private function retire(Task $task, string $how): void
    {
        $how === 'archive' ? $task->archive() : $task->delete();
    }

    #[DataProvider('ways')]
    public function test_today_and_upcoming_skip_them(string $how): void
    {
        $f = $this->teamFixture();
        $f->task->update(['title' => 'Retired due today task', 'due_date' => now()->toDateString()]);
        $other = $f->list->tasks()->create(['title' => 'Live upcoming task', 'status' => 'todo', 'due_date' => now()->addDays(2)->toDateString()]);
        $retired = $f->list->tasks()->create(['title' => 'Retired upcoming task', 'status' => 'todo', 'due_date' => now()->addDays(2)->toDateString()]);

        $this->retire($f->task, $how);
        $this->retire($retired, $how);

        Livewire::actingAs($f->editor)->test(SmartViews::class, ['view' => 'today'])
            ->assertDontSee('Retired due today task');

        Livewire::actingAs($f->editor)->test(SmartViews::class, ['view' => 'upcoming'])
            ->assertSee('Live upcoming task')
            ->assertDontSee('Retired upcoming task');
    }

    #[DataProvider('ways')]
    public function test_the_due_soon_reminder_skips_them(string $how): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'due_date' => now()->addDay()->toDateString()]);
        $this->retire($f->task, $how);

        $this->artisan(CheckTasksDueSoon::class)->assertSuccessful();

        $this->assertSame(0, $f->editor->notifications()->where('type', TaskDueSoonNotification::class)->count());
    }

    #[DataProvider('ways')]
    public function test_escalation_detection_skips_them(string $how): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => now()->subDays(20)->toDateString()]);
        $this->retire($f->task, $how);

        $this->artisan(DetectEscalationCandidates::class)->assertSuccessful();

        $this->assertSame(0, TaskEscalationProposal::count());
    }

    public function test_a_live_overdue_task_is_still_detected_next_to_an_archived_one(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['due_date' => now()->subDays(20)->toDateString()]);
        $f->task->archive();
        $live = $f->list->tasks()->create(['title' => 'Live overdue', 'status' => 'todo', 'due_date' => now()->subDays(20)->toDateString()]);

        $this->artisan(DetectEscalationCandidates::class)->assertSuccessful();

        $this->assertSame([$live->id], TaskEscalationProposal::pluck('task_id')->all());
    }

    #[DataProvider('ways')]
    public function test_the_escalation_queue_drops_a_proposal_once_its_task_is_retired(string $how): void
    {
        $f = $this->teamFixture();
        $proposal = TaskEscalationProposal::create(['task_id' => $f->task->id, 'status' => 'pending', 'reason' => 'Overdue by 9 days.']);

        Livewire::actingAs($f->owner)->test(EscalationQueue::class)->assertSee('Fixture task');

        $this->retire($f->task, $how);

        Livewire::actingAs($f->owner)->test(EscalationQueue::class)->assertDontSee('Fixture task');

        // And a stale approve/reject click on it does nothing, rather than erroring.
        Livewire::actingAs($f->owner)->test(EscalationQueue::class)->call('approve', $proposal->id)->assertOk();
        $this->assertSame('pending', $proposal->fresh()->status);
        $this->assertNull(Task::withArchived()->withTrashed()->find($f->task->id)->escalated_at);
    }

    #[DataProvider('ways')]
    public function test_a_recurring_task_that_is_retired_stops_generating(string $how): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'daily', 'recurrence_interval' => 1, 'due_date' => now()->toDateString()]);
        $this->retire($f->task, $how);

        $source = Task::withArchived()->withTrashed()->find($f->task->id);
        $spawned = app(TaskRecurrenceService::class)->spawnNextOccurrence($source);

        $this->assertNull($spawned);
        $this->assertSame(1, Task::withArchived()->withTrashed()->count());
    }

    public function test_a_recurring_task_on_an_archived_board_stops_generating(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'daily', 'recurrence_interval' => 1]);
        $source = $f->task->fresh();
        $f->list->archive();

        $this->assertNull(app(TaskRecurrenceService::class)->spawnNextOccurrence($source));
        $this->assertSame(1, Task::withoutGlobalScopes()->count());
    }

    public function test_a_live_recurring_task_still_generates(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['recurrence_type' => 'daily', 'recurrence_interval' => 1, 'due_date' => now()->toDateString()]);

        $spawned = app(TaskRecurrenceService::class)->spawnNextOccurrence($f->task->fresh());

        $this->assertNotNull($spawned);
        $this->assertSame($f->task->id, $spawned->recurrence_parent_id);
    }

    public function test_team_queries_do_not_see_retired_tasks(): void
    {
        $f = $this->teamFixture();
        $archived = $f->list->tasks()->create(['title' => 'Archived one', 'status' => 'todo']);
        $deleted = $f->list->tasks()->create(['title' => 'Deleted one', 'status' => 'todo']);
        $archived->archive();
        $deleted->delete();

        $this->assertSame([$f->task->id], Task::forTeam($f->team)->pluck('id')->all());
    }
}
