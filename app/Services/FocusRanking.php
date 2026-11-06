<?php

namespace App\Services;

use App\Models\Task;
use App\Support\Today;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What may deserve attention first, and why. It is a suggestion worked out from dates, priority and links on the tasks,
 * with the reason shown, so a person can see it and disagree.
 *
 * @phpstan-type Ranked array{task: Task, reasons: list<string>}
 */
class FocusRanking
{
    private const PRIORITY = ['urgent' => 40, 'high' => 20, 'medium' => 0, 'low' => -10];

    /**
     * @param  Collection<int, Task>  $tasks  with blockedBy and blocking loaded
     * @return list<array{task: Task, reasons: list<string>}>
     */
    public function rank(Collection $tasks, int $limit = 5): array
    {
        return $tasks->map(fn (Task $task) => $this->score($task))
            ->filter(fn (array $row) => $row['reasons'] !== [])
            ->sortByDesc('score')->take($limit)->values()
            ->map(fn (array $row) => ['task' => $row['task'], 'reasons' => $row['reasons']])->all();
    }

    /** @return array{task: Task, score: int, reasons: list<string>} */
    private function score(Task $task): array
    {
        $today = Today::date();
        $score = self::PRIORITY[$task->priority] ?? 0;
        $reasons = [];

        if (in_array($task->priority, ['urgent', 'high'], true)) {
            $reasons[] = ucfirst($task->priority).' priority';
        }

        if ($task->due_date) {
            $days = (int) $today->diffInDays($task->due_date, false);
            if ($days < 0) {
                $score += 50 + min(30, -$days);
                $reasons[] = abs($days).' '.Str::plural('day', abs($days)).' overdue';
            } elseif ($days === 0) {
                $score += 45;
                $reasons[] = 'Due today';
            } elseif ($days === 1) {
                $score += 30;
                $reasons[] = 'Due tomorrow';
            } elseif ($days <= 3) {
                $score += 15;
                $reasons[] = 'Due in '.$days.' days';
            }
        }

        $waiting = $task->blocking->where('status', '!=', 'done')->count();
        if ($waiting > 0) {
            $score += 15 * min(3, $waiting);
            $reasons[] = $waiting.' other '.Str::plural('task', $waiting).' waiting on this';
        }

        // Nothing can be done about it yet, so it should not be at the top.
        if ($task->isBlocked()) {
            $score -= 60;
            $reasons[] = 'Blocked for now';
        }

        return ['task' => $task, 'score' => $score, 'reasons' => $reasons];
    }
}
