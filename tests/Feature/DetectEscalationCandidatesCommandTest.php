<?php

namespace Tests\Feature;

use App\Console\Commands\DetectEscalationCandidates;
use App\Models\TaskEscalationProposal;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class DetectEscalationCandidatesCommandTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function taskList(User $owner): TaskList
    {
        return TaskList::create([
            'team_id' => $owner->currentTeam->id,
            'owner_id' => $owner->id,
            'name' => 'Board',
        ]);
    }

    public function test_a_task_overdue_by_more_than_seven_days_and_not_done_gets_a_pending_proposal(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Very overdue',
            'status' => 'todo',
            'due_date' => now()->subDays(8),
        ]);

        $this->artisan(DetectEscalationCandidates::class)->assertSuccessful();

        $this->assertDatabaseCount('task_escalation_proposals', 1);
        $this->assertDatabaseHas('task_escalation_proposals', [
            'task_id' => $task->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_task_overdue_by_only_three_days_gets_no_proposal(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create([
            'title' => 'Slightly overdue',
            'status' => 'todo',
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan(DetectEscalationCandidates::class)->assertSuccessful();

        $this->assertDatabaseCount('task_escalation_proposals', 0);
    }

    public function test_a_done_task_overdue_gets_no_proposal(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create([
            'title' => 'Finished late',
            'status' => 'done',
            'due_date' => now()->subDays(10),
        ]);

        $this->artisan(DetectEscalationCandidates::class)->assertSuccessful();

        $this->assertDatabaseCount('task_escalation_proposals', 0);
    }

    public function test_running_the_command_twice_does_not_duplicate_a_pending_proposal(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $taskList->tasks()->create([
            'title' => 'Very overdue',
            'status' => 'todo',
            'due_date' => now()->subDays(8),
        ]);

        $this->artisan(DetectEscalationCandidates::class);
        $this->artisan(DetectEscalationCandidates::class);

        $this->assertDatabaseCount('task_escalation_proposals', 1);
    }

    public function test_a_rejected_proposal_gets_a_fresh_one_on_the_next_eligible_run(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $taskList = $this->taskList($owner);
        $task = $taskList->tasks()->create([
            'title' => 'Very overdue',
            'status' => 'todo',
            'due_date' => now()->subDays(8),
        ]);

        $this->artisan(DetectEscalationCandidates::class);

        $existing = TaskEscalationProposal::where('task_id', $task->id)->firstOrFail();
        $existing->update(['status' => 'rejected', 'rejected_reason' => 'Not yet.']);

        $this->artisan(DetectEscalationCandidates::class);

        $this->assertDatabaseCount('task_escalation_proposals', 2);
        $this->assertSame(1, TaskEscalationProposal::where('task_id', $task->id)->where('status', 'pending')->count());
    }

    public function test_overdue_is_counted_on_the_clock_of_the_business(): void
    {
        $this->travelTo(Carbon::parse('2026-06-10 22:00:00', 'UTC'));
        $f = $this->teamFixture();
        $f->team->forceFill(['timezone' => 'Pacific/Auckland'])->save(); // Already the 11th there.
        $f->task->update(['due_date' => '2026-06-03']);

        $this->artisan('tasks:detect-escalation-candidates')->assertSuccessful();

        $this->assertSame('Overdue by 8 days.', TaskEscalationProposal::sole()->reason);
    }

    public function test_a_business_still_on_the_threshold_day_is_left_alone(): void
    {
        $this->travelTo(Carbon::parse('2026-06-11 02:00:00', 'UTC'));
        $f = $this->teamFixture();
        $f->team->forceFill(['timezone' => 'America/New_York'])->save(); // Still the 10th there.
        $f->task->update(['due_date' => '2026-06-03']);

        $this->artisan('tasks:detect-escalation-candidates')->assertSuccessful();

        $this->assertSame(0, TaskEscalationProposal::count());
    }
}
