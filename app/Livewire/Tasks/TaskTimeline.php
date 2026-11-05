<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\ShowsBoardTasks;
use App\Models\Task;
use App\Services\TaskEditor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The same tasks as bars: how long each runs, what comes first, and what waits for what. */
class TaskTimeline extends Component
{
    use ShowsBoardTasks;

    public const DAYS = 63;

    public const DAY_WIDTH = 26;

    public const ROW_HEIGHT = 36;

    /** Any day in the first week shown. */
    #[Url(except: '')]
    public string $from = '';

    protected function forgetTasks(): void
    {
        unset($this->rows, $this->lines, $this->filtersActive);
    }

    protected function ownSettings(): array
    {
        return ['view' => 'timeline'];
    }

    #[Computed]
    public function first(): Carbon
    {
        $date = Carbon::hasFormatWithModifiers($this->from, 'Y#m#d') ? Carbon::createFromFormat('!Y-m-d', $this->from) : null;
        $start = $date && $date->format('Y-m-d') === $this->from ? $date : Carbon::today()->subWeek();

        return $start->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** @return list<array{left: int, label: string}> */
    #[Computed]
    public function weeks(): array
    {
        $weeks = [];
        for ($day = 0; $day < self::DAYS; $day += 7) {
            $weeks[] = ['left' => $day * self::DAY_WIDTH + 4, 'label' => $this->first->copy()->addDays($day)->format('j M')];
        }

        return $weeks;
    }

    #[Computed]
    public function todayLeft(): ?int
    {
        $day = (int) $this->first->diffInDays(Carbon::today(), false);

        return $day >= 0 && $day < self::DAYS ? $day * self::DAY_WIDTH + (int) (self::DAY_WIDTH / 2) : null;
    }

    /**
     * Scheduled tasks in date order with their bar, then the ones with no dates.
     *
     * @return Collection<int, array{task: Task, start: ?string, due: ?string, left: ?int, width: ?int, late: bool}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $tasks = $this->filteredQuery()->with(['blockedBy:id,title,status,start_date,due_date,task_list_id'])
            ->orderByRaw('CASE WHEN tasks.due_date IS NULL AND tasks.start_date IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(tasks.start_date, tasks.due_date)')
            ->orderBy('tasks.sort_order')
            ->get()
            ->each(fn (Task $task) => $task->setRelation('taskList', $this->taskList));

        $last = $this->first->copy()->addDays(self::DAYS - 1);

        return $tasks->map(function (Task $task) use ($last) {
            $due = $task->due_date ?? $task->start_date;
            $start = $task->start_date ?? $due;

            if ($due === null) {
                return ['task' => $task, 'start' => null, 'due' => null, 'left' => null, 'width' => null, 'late' => false];
            }

            if ($due->lt($this->first) || $start->gt($last)) {
                return null;
            }

            $from = max(0, (int) $this->first->diffInDays($start, false));
            $to = min(self::DAYS - 1, (int) $this->first->diffInDays($due, false));

            return [
                'task' => $task,
                'start' => $start->toDateString(),
                'due' => $due->toDateString(),
                'left' => $from * self::DAY_WIDTH,
                'width' => ($to - $from + 1) * self::DAY_WIDTH,
                'late' => $task->status !== 'done' && $due->lt(Carbon::today()),
            ];
        })->filter()->values();
    }

    /**
     * A line from the end of each blocker to the start of the task waiting on it. A task that starts before its blocker
     * ends cannot run as drawn, so that line says so.
     *
     * @return list<array{path: string, late: bool}>
     */
    #[Computed]
    public function lines(): array
    {
        $rows = $this->rows->values();
        $index = $rows->mapWithKeys(fn (array $row, int $i) => [$row['task']->id => $i]);
        $lines = [];

        foreach ($rows as $i => $row) {
            if ($row['left'] === null) {
                continue;
            }

            foreach ($row['task']->blockedBy as $blocker) {
                $j = $index->get($blocker->id);
                if ($j === null || $rows[$j]['left'] === null) {
                    continue;
                }

                $x1 = $rows[$j]['left'] + $rows[$j]['width'];
                $y1 = $j * self::ROW_HEIGHT + (int) (self::ROW_HEIGHT / 2);
                $x2 = $row['left'];
                $y2 = $i * self::ROW_HEIGHT + (int) (self::ROW_HEIGHT / 2);
                $mid = min($x1 + 8, $x2 - 8);

                $lines[] = ['path' => "M{$x1} {$y1} H{$mid} V{$y2} H{$x2}", 'late' => $blocker->status !== 'done' && $row['start'] <= $rows[$j]['due']];
            }
        }

        return $lines;
    }

    public function go(int $weeks): void
    {
        $this->from = $this->first->copy()->addWeeks(max(-8, min(8, $weeks)))->toDateString();

        unset($this->first, $this->weeks, $this->todayLeft);
        $this->forgetTasks();
    }

    public function goToday(): void
    {
        $this->from = '';

        unset($this->first, $this->weeks, $this->todayLeft);
        $this->forgetTasks();
    }

    /**
     * Where a bar landed. The dates it was drawn with come back too, so a task someone else changed stays as they left it.
     * A task that only ever had a due date keeps it that way until it is given a length.
     *
     * @return array{ok: bool, message?: string}
     */
    public function setDates(int $taskId, string $start, string $due, ?string $fromStart = null, ?string $fromDue = null): array
    {
        $this->resetErrorBag('status');
        $this->forgetTasks();

        $task = $this->boardQuery()->find($taskId);
        if (! $task) {
            return $this->refuse('That task is no longer on this board.');
        }

        $this->authorize('update', $task);

        Validator::make(['start' => $start, 'due' => $due], ['start' => ['required', 'date_format:Y-m-d'], 'due' => ['required', 'date_format:Y-m-d']])->validate();

        $drawnDue = ($task->due_date ?? $task->start_date)?->toDateString();
        $drawnStart = ($task->start_date ?? $task->due_date)?->toDateString();
        if ($fromStart !== $drawnStart || $fromDue !== $drawnDue) {
            return $this->refuse('Someone else changed those dates, so here is where it is now.');
        }

        try {
            app(TaskEditor::class)->setDates($task, $task->start_date === null && $start === $due ? null : $start, $due);
        } catch (ValidationException $refused) {
            return $this->refuse($refused->validator->errors()->first());
        }

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();

        return ['ok' => true];
    }

    /** Give a task with no dates a place by clicking a day on its row. */
    public function schedule(int $taskId, int $day): array
    {
        return $this->setDates($taskId, $date = $this->first->copy()->addDays(max(0, min(self::DAYS - 1, $day)))->toDateString(), $date);
    }

    /** @return array{ok: bool, message: string} */
    private function refuse(string $message): array
    {
        $this->addError('status', $message);

        return ['ok' => false, 'message' => $message];
    }

    public function render(): View
    {
        return view('livewire.tasks.task-timeline');
    }
}
