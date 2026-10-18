<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TaskEscalationProposal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Detects tasks that are significantly overdue (more than 7 days past their
 * due date) and still not done, and proposes escalating them -- creating a
 * TaskEscalationProposal for the task list's owner to review. Read-and-propose
 * only: never touches the task itself. Intended to run daily (see
 * routes/console.php). Skips a task that already has an open pending
 * proposal, so re-running the command (or a missed schedule catching up)
 * doesn't create duplicates.
 */
class DetectEscalationCandidates extends Command
{
    protected $signature = 'tasks:detect-escalation-candidates';

    protected $description = 'Propose escalating tasks that are significantly overdue and not done.';

    private const OVERDUE_THRESHOLD_DAYS = 7;

    public function handle(): int
    {
        $cutoff = Carbon::today()->subDays(self::OVERDUE_THRESHOLD_DAYS)->toDateString();

        // whereHas('taskList') leaves out tasks on archived boards; archived and
        // deleted tasks are already excluded by the model's scopes.
        $tasks = Task::query()
            ->whereHas('taskList')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $cutoff)
            ->where('status', '!=', 'done')
            ->get();

        $proposed = 0;

        foreach ($tasks as $task) {
            $hasPendingProposal = TaskEscalationProposal::where('task_id', $task->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingProposal) {
                continue;
            }

            $daysOverdue = $task->due_date->diffInDays(Carbon::today());

            TaskEscalationProposal::create([
                'task_id' => $task->id,
                'status' => 'pending',
                'reason' => "Overdue by {$daysOverdue} days.",
            ]);

            $proposed++;
        }

        $this->info("Proposed {$proposed} task escalation(s).");

        return self::SUCCESS;
    }
}
