<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Support\Today;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What the reports count, each with its definition next to it. Counts of work, never a score of a person: more finished
 * tasks does not mean someone is more productive, so nothing here ranks people. Private Inboxes are left out.
 */
class TaskReports
{
    public const DEFINITIONS = [
        'created' => 'Tasks made in this period, subtasks included.',
        'finished' => 'Tasks finished in this period.',
        'overdue' => 'Open tasks past their due date today. This does not depend on the period.',
        'average' => 'Mean time from a task being made to being finished, for tasks finished in this period.',
        'median' => 'The middle of those same times, which one very old task cannot pull up.',
        'reopened' => 'Times a finished task was moved back in this period.',
        'blocked' => 'Open tasks held up today by another task. This does not depend on the period.',
        'on_time' => 'Of the repeating tasks finished in this period that had a due date, how many were finished by it.',
        'estimate' => 'Tasks finished in this period that have both an estimate and time spent: the two added up.',
    ];

    /** @return Builder<Task> */
    private function tasks(Team $team, ?int $board): Builder
    {
        return Task::forTeamUnscoped($team->id)->when($board, fn ($q) => $q->where('tasks.task_list_id', $board));
    }

    /** @return array<string, mixed> */
    public function report(Team $team, Carbon $from, Carbon $to, ?int $board = null, ?int $groupField = null): array
    {
        $tasks = fn () => $this->tasks($team, $board);
        $end = $to->copy()->endOfDay();
        $today = Today::date();

        $finished = $tasks()->whereBetween('tasks.completed_at', [$from, $end])->get(['tasks.id', 'tasks.created_at', 'tasks.completed_at', 'tasks.due_date', 'tasks.recurrence_type', 'tasks.recurrence_parent_id', 'tasks.estimated_minutes', 'tasks.actual_minutes']);

        $spans = $finished->filter(fn (Task $task) => $task->created_at !== null)
            ->map(fn (Task $task) => max(0, $task->created_at->diffInMinutes($task->completed_at)))->sort()->values();
        $repeating = $finished->filter(fn (Task $task) => ($task->recurrence_type !== null || $task->recurrence_parent_id !== null) && $task->due_date !== null);
        $measured = $finished->filter(fn (Task $task) => $task->estimated_minutes !== null && $task->actual_minutes !== null);

        $open = $tasks()->where('tasks.status', '!=', 'done');

        return [
            'created' => $tasks()->whereBetween('tasks.created_at', [$from, $end])->count(),
            'finished' => $finished->count(),
            'overdue' => (clone $open)->whereDate('tasks.due_date', '<', $today)->count(),
            'average_minutes' => $spans->isEmpty() ? null : (int) round($spans->avg()),
            'median_minutes' => $spans->isEmpty() ? null : (int) round($spans->median()),
            'reopened' => TaskActivity::where('kind', 'reopened')->whereBetween('created_at', [$from, $end])
                ->whereIn('task_id', $tasks()->select('tasks.id'))->count(),
            'blocked' => (clone $open)->with('blockedBy:id,status')->get(['tasks.id', 'tasks.status'])->filter->isBlocked()->count(),
            'on_time' => $repeating->isEmpty() ? null : ['on_time' => $repeating->filter(fn (Task $task) => $task->completed_at->startOfDay()->lte($task->due_date))->count(), 'of' => $repeating->count()],
            'estimate' => ['tasks' => $measured->count(), 'estimated' => (int) $measured->sum('estimated_minutes'), 'actual' => (int) $measured->sum('actual_minutes')],
            'by_status' => $this->counted((clone $open), 'tasks.status'),
            'by_priority' => $this->counted((clone $open), 'tasks.priority'),
            'by_person' => $this->byPerson($team, clone $open),
            'by_field' => $groupField ? $this->byField($open, $groupField) : [],
            'trend' => $this->trend($finished, $from, $to),
        ];
    }

    /** @return array<string, int> */
    private function counted(Builder $query, string $column): array
    {
        return $query->toBase()->selectRaw("{$column} AS name, COUNT(*) AS total")->groupBy($column)->pluck('total', 'name')->map(fn ($total) => (int) $total)->all();
    }

    /** @return list<array{name: string, open: int, overdue: int, hours: float}> in name order, so it cannot read as a ranking */
    private function byPerson(Team $team, Builder $open): array
    {
        $today = Today::date();
        $people = $team->allUsers()->keyBy('id');

        return $open->get(['tasks.assignee_id', 'tasks.due_date', 'tasks.estimated_minutes'])
            ->groupBy(fn (Task $task) => $task->assignee_id ?? 0)
            ->map(fn ($held, int $id) => [
                'name' => $id === 0 ? 'Unassigned' : ($people->get($id)?->name ?? 'Someone who left'),
                'open' => $held->count(),
                'overdue' => $held->filter(fn (Task $task) => $task->due_date && $task->due_date->lt($today))->count(),
                'hours' => round($held->sum('estimated_minutes') / 60, 1),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Open work split by a custom field, such as a department. @return array<string, int> */
    private function byField(Builder $open, int $field): array
    {
        $values = (clone $open)->leftJoin('task_field_values as fv', fn ($join) => $join->on('fv.task_id', '=', 'tasks.id')->where('fv.task_field_id', $field))
            ->toBase()->selectRaw('COALESCE(fv.value, ?) AS name, COUNT(*) AS total', ['Not set'])->groupBy('name')->orderBy('name')->pluck('total', 'name');

        return $values->map(fn ($total) => (int) $total)->all();
    }

    /** @return list<array{label: string, finished: int}> by week, or by day for a period of up to 31 days */
    private function trend($finished, Carbon $from, Carbon $to): array
    {
        $daily = $from->diffInDays($to) <= 31;
        $step = $daily ? 'addDay' : 'addWeek';
        $start = $daily ? $from->copy()->startOfDay() : $from->copy()->startOfWeek(Carbon::MONDAY);
        $counts = $finished->countBy(fn (Task $task) => ($daily ? $task->completed_at->copy() : $task->completed_at->copy()->startOfWeek(Carbon::MONDAY))->toDateString());

        $rows = [];
        for ($at = $start; $at->lte($to); $at = $at->copy()->{$step}()) {
            $rows[] = ['label' => $at->format('j M'), 'finished' => (int) $counts->get($at->toDateString(), 0)];
        }

        return $rows;
    }

    /** A CSV of the report, with anything a spreadsheet would run as a formula made harmless. @return list<list<string|int|float|null>> */
    public function rows(array $report, Carbon $from, Carbon $to): array
    {
        $rows = [['Measure', 'Value', 'Definition'], ['From', $from->toDateString(), ''], ['To', $to->toDateString(), '']];

        foreach (['created' => 'Created', 'finished' => 'Finished', 'overdue' => 'Overdue now', 'reopened' => 'Reopened', 'blocked' => 'Blocked now'] as $key => $name) {
            $rows[] = [$name, $report[$key], self::DEFINITIONS[$key]];
        }

        $rows[] = ['Average minutes to finish', $report['average_minutes'], self::DEFINITIONS['average']];
        $rows[] = ['Median minutes to finish', $report['median_minutes'], self::DEFINITIONS['median']];
        $rows[] = ['Repeating tasks on time', $report['on_time'] ? $report['on_time']['on_time'].' of '.$report['on_time']['of'] : null, self::DEFINITIONS['on_time']];
        $rows[] = ['Estimated minutes', $report['estimate']['estimated'], self::DEFINITIONS['estimate']];
        $rows[] = ['Actual minutes', $report['estimate']['actual'], self::DEFINITIONS['estimate']];

        foreach ($report['by_status'] as $name => $total) {
            $rows[] = ['Open by status: '.$name, $total, ''];
        }

        foreach ($report['by_priority'] as $name => $total) {
            $rows[] = ['Open by priority: '.$name, $total, ''];
        }

        foreach ($report['by_person'] as $row) {
            $rows[] = ['Open with '.$row['name'], $row['open'], $row['overdue'].' overdue, '.$row['hours'].' hours estimated'];
        }

        foreach ($report['by_field'] as $name => $total) {
            $rows[] = ['Open by field: '.$name, $total, ''];
        }

        foreach ($report['trend'] as $row) {
            $rows[] = ['Finished from '.$row['label'], $row['finished'], ''];
        }

        return $this->harmless($rows);
    }

    /** @param list<list<string|int|float|null>> $rows */
    private function harmless(array $rows): array
    {
        return array_map(fn (array $row) => array_map(fn ($cell) => is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell, $row), $rows);
    }
}
