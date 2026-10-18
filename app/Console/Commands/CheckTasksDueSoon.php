<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskDueSoonNotification;
use App\Services\Notify\NotifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Notifies a task's assignee when the task is due within the next two days
 * and hasn't been completed yet. Intended to run daily (see
 * routes/console.php). Skips a task if a due-soon notification for it was
 * already sent in the last 24 hours, so re-running the command (or a missed
 * schedule catching up) doesn't spam the same reminder every run.
 */
class CheckTasksDueSoon extends Command
{
    protected $signature = 'tasks:check-due-soon';

    protected $description = 'Notify task assignees about tasks due within the next two days.';

    public function handle(): int
    {
        $windowEnd = Carbon::today()->addDays(2)->toDateString();
        $windowStart = Carbon::today()->toDateString();

        // whereHas('taskList') leaves out tasks on archived boards; archived and
        // deleted tasks are already excluded by the model's scopes.
        $tasks = Task::query()
            ->whereHas('taskList')
            ->whereNotNull('due_date')
            ->whereNotNull('assignee_id')
            ->whereDate('due_date', '<=', $windowEnd)
            ->whereDate('due_date', '>=', $windowStart)
            ->where('status', '!=', 'done')
            ->with('assignee')
            ->get();

        $notified = 0;

        foreach ($tasks as $task) {
            $assignee = $task->assignee;
            if (! $assignee) {
                continue;
            }

            // Filtered in PHP rather than a `data->task_id` JSON-path query so this
            // works identically regardless of the notifications.data column's declared
            // type across database drivers.
            $alreadyNotified = $assignee->notifications()
                ->where('type', TaskDueSoonNotification::class)
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->get()
                ->contains(fn ($n) => ($n->data['task_id'] ?? null) === $task->id);

            if ($alreadyNotified) {
                continue;
            }

            $assignee->notify(new TaskDueSoonNotification($task));
            app(NotifyClient::class)->taskDueSoon($task, $assignee);
            $notified++;
        }

        $this->info("Sent {$notified} task due-soon notification(s).");

        return self::SUCCESS;
    }
}
