<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskDueSoonNotification;
use App\Services\NotificationPreferences;
use App\Services\Notify\NotifyClient;
use App\Support\Today;
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
        // A day wider than the window each way: every team's today is somewhere inside this, and each task is checked
        // against its own team's calendar below.
        $windowEnd = Today::date()->addDays(3)->toDateString();
        $windowStart = Today::date()->subDay()->toDateString();

        // whereHas('taskList') leaves out tasks on archived boards; archived and
        // deleted tasks are already excluded by the model's scopes.
        $tasks = Task::query()
            ->whereHas('taskList')
            ->whereNotNull('due_date')
            ->whereNotNull('assignee_id')
            ->whereDate('due_date', '<=', $windowEnd)
            ->whereDate('due_date', '>=', $windowStart)
            ->where('status', '!=', 'done')
            ->with(['assignee', 'taskList.team'])
            ->get();

        $notified = 0;

        foreach ($tasks as $task) {
            $assignee = $task->assignee;
            if (! $assignee) {
                continue;
            }

            Today::use($task->taskList->team?->timezone);
            if ($task->due_date->lt(Today::date()) || $task->due_date->gt(Today::date()->addDays(2))) {
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
            if (app(NotificationPreferences::class)->wants($assignee, 'due_soon', 'email')) {
                app(NotifyClient::class)->taskDueSoon($task, $assignee);
            }
            $notified++;
        }

        Today::use(null);

        $this->info("Sent {$notified} task due-soon notification(s).");

        return self::SUCCESS;
    }
}
