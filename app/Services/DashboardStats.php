<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * The dashboard's task counts for one team, in a single query.
 *
 * Counts go through Task::forTeam like every team-wide query, so another
 * team's tasks never leak in; archived and deleted tasks and tasks on archived
 * boards are already left out by the models' scopes. Due-today and overdue
 * mean "not done yet". "Completed this week" counts done tasks last touched
 * since Monday, which is when a task was moved to done (there is no separate
 * completed-at column).
 */
class DashboardStats
{
    /**
     * @return array{total: int, todo: int, in_progress: int, review: int, done: int, due_today: int, overdue: int, completed_week: int}
     */
    public function forTeam(Team $team): array
    {
        $today = Carbon::today()->toDateString();
        $weekStart = Carbon::now()->startOfWeek()->toDateTimeString();

        $row = Task::forTeam($team)
            ->toBase()
            ->selectRaw(
                "COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN tasks.status = 'todo' THEN 1 ELSE 0 END), 0) AS todo,
                COALESCE(SUM(CASE WHEN tasks.status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress,
                COALESCE(SUM(CASE WHEN tasks.status = 'review' THEN 1 ELSE 0 END), 0) AS review,
                COALESCE(SUM(CASE WHEN tasks.status = 'done' THEN 1 ELSE 0 END), 0) AS done,
                COALESCE(SUM(CASE WHEN tasks.status <> 'done' AND DATE(tasks.due_date) = ? THEN 1 ELSE 0 END), 0) AS due_today,
                COALESCE(SUM(CASE WHEN tasks.status <> 'done' AND DATE(tasks.due_date) < ? THEN 1 ELSE 0 END), 0) AS overdue,
                COALESCE(SUM(CASE WHEN tasks.status = 'done' AND tasks.updated_at >= ? THEN 1 ELSE 0 END), 0) AS completed_week",
                [$today, $today, $weekStart]
            )
            ->first();

        return [
            'total' => (int) $row->total,
            'todo' => (int) $row->todo,
            'in_progress' => (int) $row->in_progress,
            'review' => (int) $row->review,
            'done' => (int) $row->done,
            'due_today' => (int) $row->due_today,
            'overdue' => (int) $row->overdue,
            'completed_week' => (int) $row->completed_week,
        ];
    }
}
