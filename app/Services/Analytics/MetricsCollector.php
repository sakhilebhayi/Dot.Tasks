<?php

namespace App\Services\Analytics;

use App\Models\Task;
use App\Models\Team;
use Carbon\CarbonInterface;

/**
 * The aggregate Dot.Tasks numbers one company publishes to Dot.Analytics, under Analytics' catalogue keys for
 * the `tasks` platform (config/metrics.php there). Counts only: never a title, name or any row.
 *
 * Pure queries, scoped to the company explicitly with Task::forTeam, so the result never depends on who (if
 * anyone) is signed in. Archived tasks, soft-deleted tasks and tasks on archived boards are left out by the
 * models' own scopes, exactly as the dashboard leaves them out. "Today" is the UTC day and the 7 day window is
 * the seven 24 hour periods ending at $asOf.
 *
 * Reported:
 *   open        tasks not done yet
 *   overdue     open tasks whose due date is before today (UTC)
 *   created_7d  tasks created in the last 7 days
 *
 * NOT reported: `completed_7d`. Dot.Tasks has no completed-at column (the dashboard's "completed this week"
 * says so: it counts done tasks last *touched* in the window, which also counts an old task edited after it was
 * done). Sending that would be a guess presented as a measurement, so the key is omitted until the app records
 * when a task was completed.
 */
class MetricsCollector
{
    /** @return array{open: int, overdue: int, created_7d: int} */
    public function collect(Team|int $team, ?CarbonInterface $asOf = null): array
    {
        $now = ($asOf ? $asOf->copy() : now())->utc();
        $today = $now->toDateString();
        $since = $now->copy()->subDays(7)->setTimezone((string) config('app.timezone', 'UTC'));

        $open = fn () => Task::query()->forTeam($team)->where('tasks.status', '!=', 'done');

        return [
            'open' => $open()->count(),
            'overdue' => $open()->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', $today)->count(),
            'created_7d' => Task::query()->forTeam($team)->where('tasks.created_at', '>=', $since)->count(),
        ];
    }
}
