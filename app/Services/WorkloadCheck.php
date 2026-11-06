<?php

namespace App\Services;

use App\Models\Task;
use App\Support\Today;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Where a person's work looks too heavy, from their open tasks. Every line is a count anyone can check. */
class WorkloadCheck
{
    private const FEW = 5;

    /**
     * @param  Collection<int, Task>  $held  this person's open tasks
     * @return list<string>
     */
    public function flags(Collection $held, float $teamAverage): array
    {
        $today = Today::date();
        $week = $held->filter(fn (Task $task) => $task->due_date && $task->due_date->gte($today) && $task->due_date->lte($today->copy()->addDays(7)));
        $hours = round($week->sum('estimated_minutes') / 60, 1);
        $limit = (int) config('tasks.weekly_hours', 40);

        $busiest = $week->groupBy(fn (Task $task) => $task->due_date->toDateString())
            ->map(fn (Collection $tasks, string $date) => ['date' => $date, 'count' => $tasks->count()])
            ->sortByDesc('count')->first();

        return array_values(array_filter([
            $held->count() >= self::FEW && $held->count() > 1.5 * $teamAverage ? 'Holds '.$held->count().' tasks. The team average is '.round($teamAverage, 1).'.' : null,
            $hours > $limit ? $hours.' hours due in 7 days, over '.$limit.'.' : null,
            $busiest && $busiest['count'] >= 3 ? $busiest['count'].' '.Str::plural('task', $busiest['count']).' due on '.date('l', strtotime($busiest['date'])).'.' : null,
        ]));
    }
}
