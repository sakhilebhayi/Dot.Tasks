<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\ShowsBoardTasks;
use App\Models\Task;
use App\Services\TaskEditor;
use App\Services\TaskHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The same tasks by their due date, across a month, a week or a day. */
class TaskCalendar extends Component
{
    use ShowsBoardTasks;

    public const RANGES = ['month' => 'Month', 'week' => 'Week', 'day' => 'Day'];

    #[Url(except: 'month')]
    public string $range = 'month';

    /** Any day inside the range shown. */
    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $state = '';

    protected function forgetTasks(): void
    {
        unset($this->days, $this->undated, $this->filtersActive);
    }

    protected function ownSettings(): array
    {
        return ['view' => 'calendar', 'range' => $this->range === 'month' ? '' : $this->range, 'state' => $this->state];
    }

    #[Computed]
    public function rangeName(): string
    {
        return array_key_exists($this->range, self::RANGES) ? $this->range : 'month';
    }

    /** The day in the address bar, or today when that is missing or not a date. */
    #[Computed]
    public function anchor(): Carbon
    {
        $date = Carbon::hasFormatWithModifiers($this->date, 'Y#m#d') ? Carbon::createFromFormat('!Y-m-d', $this->date) : null;

        return $date && $date->format('Y-m-d') === $this->date ? $date : Carbon::today();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(): array
    {
        $anchor = $this->anchor;

        return match ($this->rangeName) {
            'day' => [$anchor->copy(), $anchor->copy()],
            'week' => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            default => [$anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
        };
    }

    #[Computed]
    public function title(): string
    {
        [$from, $to] = $this->window();

        return match ($this->rangeName) {
            'day' => $this->anchor->format('l, j F Y'),
            'week' => $from->format('j M').' to '.$to->format('j M Y'),
            default => $this->anchor->format('F Y'),
        };
    }

    /** @return list<array{date: string, number: string, label: string, inRange: bool, today: bool, tasks: \Illuminate\Support\Collection<int, Task>}> */
    #[Computed]
    public function days(): array
    {
        [$from, $to] = $this->window();

        $tasks = $this->scoped()
            ->whereDate('tasks.due_date', '>=', $from)
            ->whereDate('tasks.due_date', '<=', $to)
            ->with(['assignee'])
            ->orderByRaw("CASE tasks.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('tasks.id')
            ->get()
            ->groupBy(fn (Task $task) => $task->due_date->toDateString());

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'number' => $day->format('j'),
                'label' => $day->format('D, j M'),
                'inRange' => $this->rangeName !== 'month' || $day->month === $this->anchor->month,
                'today' => $day->isToday(),
                'tasks' => $tasks->get($day->toDateString(), collect()),
            ];
        }

        return $days;
    }

    /** @return Collection<int, Task> what has no date yet, ready to be dragged onto one */
    #[Computed]
    public function undated(): Collection
    {
        return $this->scoped()->whereNull('tasks.due_date')->where('tasks.status', '!=', 'done')->orderBy('tasks.sort_order')->limit(100)->get();
    }

    /** @return Builder<Task> */
    private function scoped(): Builder
    {
        return $this->filteredQuery()->when(in_array($this->state, Task::STATUSES, true), fn ($q) => $q->where('tasks.status', $this->state));
    }

    /** Next or previous month, week or day. */
    public function go(int $step): void
    {
        $step = $step <=> 0;
        $next = match ($this->rangeName) {
            'day' => $this->anchor->copy()->addDays($step),
            'week' => $this->anchor->copy()->addWeeks($step),
            default => $this->anchor->copy()->startOfMonth()->addMonths($step),
        };
        $this->date = $next->toDateString();

        unset($this->anchor);
        $this->forgetTasks();
    }

    public function goToday(): void
    {
        $this->date = '';
        unset($this->anchor);
        $this->forgetTasks();
    }

    /**
     * Where a card landed. $from is the date the person saw it on, so a card someone else moved stays where they put it.
     *
     * @return array{ok: bool, message?: string}
     */
    public function reschedule(int $taskId, ?string $date, ?string $from = null): array
    {
        $this->resetErrorBag('status');
        $this->forgetTasks();

        $task = $this->boardQuery()->find($taskId);
        if (! $task) {
            return $this->refuse('That card is no longer on this board.');
        }

        $this->authorize('update', $task);

        if ($task->due_date?->toDateString() !== $from) {
            return $this->refuse('Someone else moved that card, so here is where it is now.');
        }

        Validator::make(['date' => $date], ['date' => ['nullable', 'date_format:Y-m-d']])->validate();

        try {
            app(TaskEditor::class)->reschedule($task, $date);
        } catch (ValidationException $refused) {
            return $this->refuse($refused->validator->errors()->first());
        }

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();

        return ['ok' => true];
    }

    /** @return array{ok: bool, message: string} */
    private function refuse(string $message): array
    {
        $this->addError('status', $message);

        return ['ok' => false, 'message' => $message];
    }

    /** Add a task on a date by typing its title there. */
    public function addOn(string $date, string $title): void
    {
        $this->authorize('create', [Task::class, $this->taskList]);

        Validator::make(['date' => $date, 'title' => trim($title)], [
            'date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
        ])->validate();

        $task = Task::create([
            'task_list_id' => $this->taskList->id,
            'creator_id' => auth()->id(),
            'title' => trim($title),
            'status' => 'todo',
            'priority' => 'medium',
            'due_date' => $date,
            'sort_order' => (int) $this->taskList->tasks()->where('status', 'todo')->max('sort_order') + 1,
        ]);
        app(TaskHistory::class)->record($task, 'created', 'Created it');

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetTasks();
    }

    public function render(): View
    {
        return view('livewire.tasks.task-calendar');
    }
}
