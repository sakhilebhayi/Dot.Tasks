<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Services\DayPlanner;
use App\Services\TaskEditor;
use App\Services\TaskStatusService;
use App\Support\Today;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** One person's day: the week ahead in a rack, the plan in their own order, and what is asking for attention. */
class MyDay extends Component
{
    private const SHOWN = 30;

    /** The day picked in the rack; today until another is picked. */
    public string $picked = '';

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    /** @return Builder<Task> tasks that are this person's: given to them, shared with them, or in their Inbox */
    private function mine(): Builder
    {
        $me = auth()->id();

        return Task::forTeam($this->user()->currentTeam)
            ->where(fn ($q) => $q->where('tasks.assignee_id', $me)
                ->orWhereHas('collaborators', fn ($people) => $people->whereKey($me))
                ->orWhereHas('taskList', fn ($list) => $list->where('is_inbox', true))) // forTeam shows nobody else's Inbox.
            ->with(['taskList:id,team_id,name,is_inbox,owner_id', 'blockedBy:id,status']);
    }

    /** @return Collection<int, Task> */
    private function open(Builder $query): Collection
    {
        return $query->where('tasks.status', '!=', 'done')->whereNotIn('tasks.id', $this->planIds)->orderBy('tasks.due_date')->orderBy('tasks.id')->limit(self::SHOWN)->get();
    }

    #[Computed]
    public function planIds(): array
    {
        return app(DayPlanner::class)->ids($this->user(), Today::date())->all();
    }

    /** @return \Illuminate\Support\Collection<int, Task> */
    #[Computed]
    public function plan()
    {
        $order = array_flip($this->planIds);

        return Task::forTeam($this->user()->currentTeam)->whereIn('tasks.id', $this->planIds)
            ->with(['taskList:id,team_id,name,is_inbox,owner_id', 'blockedBy:id,status'])->get()
            ->sortBy(fn (Task $task) => $order[$task->id])->values();
    }

    /** What was left unfinished on the last day that had a plan. */
    #[Computed]
    public function leftOver(): int
    {
        $planner = app(DayPlanner::class);
        $last = $planner->lastDayBefore($this->user(), Today::date());

        return $last === null ? 0 : $this->mineFrom($planner->ids($this->user(), $last)->all())->count();
    }

    /** @param  list<int>  $ids */
    private function mineFrom(array $ids): Builder
    {
        return Task::forTeam($this->user()->currentTeam)->whereIn('tasks.id', $ids)->whereNotIn('tasks.id', $this->planIds)->where('tasks.status', '!=', 'done');
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function overdue(): Collection
    {
        return $this->open($this->mine()->whereDate('tasks.due_date', '<', Today::date()));
    }

    #[Computed]
    public function day(): Carbon
    {
        return Carbon::hasFormatWithModifiers($this->picked, 'Y#m#d') ? Carbon::createFromFormat('!Y-m-d', $this->picked) : Today::date();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function dueOnDay(): Collection
    {
        return $this->open($this->mine()->whereDate('tasks.due_date', $this->day));
    }

    /** Blocked, waiting in Review, or urgent with no date to force it. */
    #[Computed]
    public function attention(): Collection
    {
        return $this->open($this->mine()->where(fn ($q) => $q->where('tasks.status', 'review')
            ->orWhere(fn ($urgent) => $urgent->where('tasks.priority', 'urgent')->whereNull('tasks.due_date'))
            // Unqualified: in a link from tasks to tasks, the other task is an alias, and `tasks.status` would be this one.
            ->orWhereHas('blockedBy', fn ($b) => $b->where(fn ($link) => $link->whereIn('task_dependencies.type', ['ff', 'fs'])->where('status', '!=', 'done'))
                ->orWhere(fn ($link) => $link->whereIn('task_dependencies.type', ['ss', 'sf'])->where('status', 'todo')))));
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function upcoming(): Collection
    {
        return $this->open($this->mine()->whereDate('tasks.due_date', '>', Today::date())->whereDate('tasks.due_date', '<=', Today::date()->addDays(7)));
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function finished(): Collection
    {
        return $this->mine()->where('tasks.status', 'done')->where('tasks.completed_at', '>=', Today::date()->subDays(2))
            ->orderByDesc('tasks.completed_at')->limit(5)->get();
    }

    /**
     * Monday to Sunday, each with the edges of the cards due that day. Work finished is off the rack, so what shows on a
     * day gone by is late.
     *
     * @return list<array{date: string, name: string, number: string, today: bool, past: bool, tasks: \Illuminate\Support\Collection<int, Task>}>
     */
    #[Computed]
    public function week(): array
    {
        $today = Today::date();
        $monday = $today->copy()->startOfWeek(Carbon::MONDAY);

        $tasks = $this->mine()->where('tasks.status', '!=', 'done')
            ->whereDate('tasks.due_date', '>=', $monday)->whereDate('tasks.due_date', '<=', $monday->copy()->addDays(6))
            ->get()->groupBy(fn (Task $task) => $task->due_date->toDateString());

        return collect(range(0, 6))->map(function (int $offset) use ($monday, $today, $tasks) {
            $day = $monday->copy()->addDays($offset);

            return [
                'date' => $day->toDateString(),
                'name' => $day->format('D'),
                'number' => $day->format('j'),
                'today' => $day->isSameDay($today),
                'past' => $day->lt($today),
                'tasks' => $tasks->get($day->toDateString(), collect()),
            ];
        })->all();
    }

    public function pick(string $date): void
    {
        $this->picked = $date === $this->picked ? '' : $date;

        unset($this->day, $this->dueOnDay);
    }

    /**
     * On today's plan, or to another place in it. A plan is a person's own, so seeing the task is all it takes.
     *
     * @return array{ok: bool, message?: string}
     */
    public function planIt(int $taskId, ?int $beforeId = null): array
    {
        $task = Task::forTeam($this->user()->currentTeam)->find($taskId);
        if (! $task) {
            return ['ok' => false, 'message' => 'That task is no longer here.'];
        }

        $this->authorize('view', $task);

        app(DayPlanner::class)->put($this->user(), $task, Today::date(), $beforeId);
        $this->forget();

        return ['ok' => true];
    }

    public function unplan(int $taskId): void
    {
        $task = Task::withArchived()->find($taskId);
        if ($task) {
            app(DayPlanner::class)->remove($this->user(), $task, Today::date());
        }

        $this->forget();
    }

    /** Bring over what was left unfinished on the last planned day, after what is already on today's plan. */
    public function carryOver(): void
    {
        $planner = app(DayPlanner::class);
        $last = $planner->lastDayBefore($this->user(), Today::date());
        if ($last === null) {
            return;
        }

        $ids = $planner->ids($this->user(), $last)->all();
        $order = array_flip($ids);
        $this->mineFrom($ids)->get()->sortBy(fn (Task $task) => $order[$task->id])
            ->each(fn (Task $task) => $planner->put($this->user(), $task, Today::date()));

        $this->forget();
    }

    /**
     * Dropped on a day of the rack.
     *
     * @return array{ok: bool, message?: string}
     */
    public function reschedule(int $taskId, string $date, ?string $from = null): array
    {
        $this->resetErrorBag('status');
        $this->forget();

        $task = Task::forTeam($this->user()->currentTeam)->find($taskId);
        if (! $task) {
            return $this->refuse('That task is no longer here.');
        }

        $this->authorize('update', $task);

        if ($task->due_date?->toDateString() !== $from) {
            return $this->refuse('Someone else changed that date, so here is where it is now.');
        }

        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        try {
            app(TaskEditor::class)->reschedule($task, $date);
        } catch (ValidationException $refused) {
            return $this->refuse($refused->validator->errors()->first());
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, message: string} */
    private function refuse(string $message): array
    {
        $this->addError('status', $message);

        return ['ok' => false, 'message' => $message];
    }

    public function toggleDone(int $taskId): void
    {
        $this->resetErrorBag('status');

        $task = Task::forTeam($this->user()->currentTeam)->find($taskId) ?? abort(404);
        $this->authorize('move', $task);

        app(TaskStatusService::class)->move($task, $task->status === 'done' ? 'todo' : 'done');

        $this->forget();
    }

    #[On('task-created')]
    #[On('task-updated')]
    public function refreshBoard(): void
    {
        $this->forget();
    }

    private function forget(): void
    {
        unset($this->planIds, $this->plan, $this->leftOver, $this->overdue, $this->dueOnDay, $this->attention, $this->upcoming, $this->finished, $this->week);
    }

    public function render(): View
    {
        return view('livewire.tasks.my-day');
    }
}
