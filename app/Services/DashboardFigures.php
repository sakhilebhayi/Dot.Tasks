<?php

namespace App\Services;

use App\Models\DayPlan;
use App\Models\Task;
use App\Models\TaskEscalationProposal;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Support\Today;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Everything on the dashboards, counted from task records as they stand. Nothing here estimates or scores anyone. */
class DashboardFigures
{
    private const LISTED = 8;

    private const WEEKS = 12;

    /** A task overdue this long, or an open escalation, is one a manager should see. */
    private const LONG_OVERDUE_DAYS = 7;

    /** @return Builder<Task> */
    private function open(Builder $query): Builder
    {
        return $query->where('tasks.status', '!=', 'done');
    }

    /** @return array<string, mixed> */
    public function personal(User $user): array
    {
        $team = $user->currentTeam;
        $today = Today::date();
        $mine = fn () => Task::forTeam($team)->mine($user)->with(['taskList:id,team_id,name,is_inbox,owner_id', 'blockedBy:id,status']);

        $planned = DayPlan::where('user_id', $user->id)->whereDate('day', $today)->pluck('task_id');
        $mentions = $user->unreadNotifications()->where('type', MentionedInCommentNotification::class)->limit(self::LISTED)->get();
        $mentioned = Task::forTeam($team)->whereIn('tasks.id', $mentions->map(fn ($note) => $note->data['task_id'] ?? null)->filter())->with('taskList:id,team_id,name,is_inbox,owner_id')->get();
        $toApprove = Task::forTeam($team)->whereHas('approvals', fn ($q) => $q->where('approver_id', $user->id)->where('status', 'pending'))->with('taskList:id,team_id,name,is_inbox,owner_id')->limit(self::LISTED)->get();

        return [
            'plan' => ['total' => $planned->count(), 'done' => Task::forTeam($team)->whereIn('tasks.id', $planned)->where('tasks.status', 'done')->count()],
            'mine' => $this->open($mine())->count(),
            'overdue' => $this->open($mine())->whereDate('tasks.due_date', '<', $today)->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
            'due_soon' => $this->open($mine())->whereDate('tasks.due_date', '>=', $today)->whereDate('tasks.due_date', '<=', $today->copy()->addDays(2))->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
            'upcoming' => $this->open($mine())->whereDate('tasks.due_date', '>', $today->copy()->addDays(2))->whereDate('tasks.due_date', '<=', $today->copy()->addDays(14))->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
            'blocked' => $this->open($mine())->limit(200)->get()->filter->isBlocked()->take(self::LISTED)->values(),
            'high' => $this->open($mine())->whereIn('tasks.priority', ['high', 'urgent'])->orderByPriorityRank()->limit(self::LISTED)->get(),
            'recent' => $mine()->where('tasks.status', 'done')->whereNotNull('tasks.completed_at')->orderByDesc('tasks.completed_at')->limit(self::LISTED)->get(),
            'awaiting' => $toApprove->merge($mentioned)->unique('id')->values(),
        ];
    }

    /** @return Builder<Task> */
    private function teamTasks(Team $team, ?int $board): Builder
    {
        return Task::forTeamUnscoped($team->id)->when($board, fn ($q) => $q->where('tasks.task_list_id', $board));
    }

    /** @return array<string, mixed> */
    public function team(Team $team, ?int $board = null): array
    {
        $today = Today::date();
        $tasks = fn () => $this->teamTasks($team, $board);
        $listed = fn () => $this->open($tasks())->with(['taskList:id,name', 'assignee:id,name', 'blockedBy:id,title,status']);

        $open = $this->open($tasks())->get(['tasks.id', 'tasks.assignee_id', 'tasks.due_date', 'tasks.status']);
        $people = $team->allUsers()->keyBy('id');

        $workload = $open->groupBy(fn (Task $task) => $task->assignee_id ?? 0)
            ->map(fn (Collection $held, int $id) => [
                'name' => $id === 0 ? 'Unassigned' : ($people->get($id)?->name ?? 'Someone who left'),
                'open' => $held->count(),
                'overdue' => $held->filter(fn (Task $task) => $task->due_date && $task->due_date->lt($today))->count(),
            ])->sortByDesc('open')->values()->all();

        return [
            'workload' => $workload,
            'status' => $this->byStatus($tasks()),
            'trend' => $this->trend($tasks()),
            'overdue' => $listed()->whereDate('tasks.due_date', '<', $today)->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
            'upcoming' => $listed()->whereDate('tasks.due_date', '>=', $today)->whereDate('tasks.due_date', '<=', $today->copy()->addDays(7))->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
            'blocked' => $listed()->whereHas('blockedBy')->limit(200)->get()->filter->isBlocked()->take(self::LISTED)->values(),
            'unassigned' => $listed()->whereNull('tasks.assignee_id')->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
            'attention' => $listed()->where(fn ($q) => $q->whereDate('tasks.due_date', '<', $today->copy()->subDays(self::LONG_OVERDUE_DAYS))
                ->orWhereIn('tasks.id', TaskEscalationProposal::where('status', 'pending')->select('task_id')))
                ->orderBy('tasks.due_date')->limit(self::LISTED)->get(),
        ];
    }

    /** Boards as a whole. No names: the business view is about how work moves, not about any one person. */
    public function business(Team $team): array
    {
        $today = Today::date();
        $since = Carbon::now()->subDays(30);

        $boards = $team->boards()->orderBy('name')->get(['id', 'name'])->map(function (TaskList $board) use ($team, $today, $since) {
            $tasks = fn () => $this->teamTasks($team, $board->id);
            $open = $this->open($tasks())->with('blockedBy:id,status')->get(['tasks.id', 'tasks.due_date', 'tasks.status']);

            return [
                'id' => $board->id,
                'name' => $board->name,
                'open' => $open->count(),
                'overdue' => $open->filter(fn (Task $task) => $task->due_date && $task->due_date->lt($today))->count(),
                'blocked' => $open->filter->isBlocked()->count(),
                'finished' => $tasks()->where('tasks.completed_at', '>=', $since)->count(),
            ];
        })->all();

        return ['boards' => $boards, 'status' => $this->byStatus($this->teamTasks($team, null)), 'trend' => $this->trend($this->teamTasks($team, null))];
    }

    /** @return array<string, int> open and finished work by what its column means */
    private function byStatus(Builder $tasks): array
    {
        $counts = $tasks->toBase()->selectRaw('tasks.status AS status, COUNT(*) AS total')->groupBy('tasks.status')->pluck('total', 'status');

        return collect(['todo', 'in_progress', 'review', 'done'])->mapWithKeys(fn (string $status) => [$status => (int) $counts->get($status, 0)])->all();
    }

    /** @return list<array{label: string, finished: int}> finished each week, Monday to Sunday, this week last */
    private function trend(Builder $tasks): array
    {
        $monday = Today::date()->startOfWeek(Carbon::MONDAY);
        $first = $monday->copy()->subWeeks(self::WEEKS - 1);

        $done = $tasks->where('tasks.completed_at', '>=', $first)->pluck('tasks.completed_at')
            ->countBy(fn ($at) => Carbon::parse($at)->startOfWeek(Carbon::MONDAY)->toDateString());

        return collect(range(0, self::WEEKS - 1))->map(function (int $week) use ($first, $done) {
            $start = $first->copy()->addWeeks($week);

            return ['label' => $start->format('j M'), 'finished' => (int) $done->get($start->toDateString(), 0)];
        })->all();
    }
}
