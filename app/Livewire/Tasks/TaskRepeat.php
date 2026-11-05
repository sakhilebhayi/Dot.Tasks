<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Services\RecurrenceEditor;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** How a task repeats, inside its panel. */
class TaskRepeat extends Component
{
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    #[Locked]
    public Task $task;

    public string $kind = 'weekly';

    public int|string $every = 1;

    /** @var list<int|string> */
    public array $days = [];

    public string $basis = 'due_date';

    public ?string $until = null;

    public int|string|null $limit = null;

    public string $missed = 'skip';

    public string $notice = '';

    public function mount(Task $task): void
    {
        $this->authorize('view', $task);
        $this->task = $task;

        if (! $task->isRecurring()) {
            return;
        }

        $interval = $task->recurrence_interval ?? 1;
        $yearly = $task->recurrence_type === 'monthly' && $interval % 12 === 0;

        $this->kind = match (true) {
            $yearly => 'yearly',
            $task->recurrence_type === 'custom_days' => 'daily',
            default => $task->recurrence_type,
        };
        $this->every = $yearly ? $interval / 12 : $interval;
        $this->days = $task->recurrence_days ?? [];
        $this->basis = $task->recurrence_anchor ?? 'due_date';
        $this->until = $task->recurrence_until?->toDateString();
        $this->limit = $task->recurrence_limit;
        $this->missed = $task->recurrence_missed ?? 'skip';
    }

    public function save(): void
    {
        $this->authorize('update', $this->task);
        $this->notice = '';

        try {
            app(RecurrenceEditor::class)->set($this->task, [
                'kind' => $this->kind, 'every' => $this->every, 'days' => $this->days, 'basis' => $this->basis,
                'until' => $this->until ?: null, 'limit' => $this->limit ?: null, 'missed' => $this->missed,
            ]);
        } catch (ValidationException $refused) {
            $this->setErrorBag($refused->validator->errors());

            return;
        }

        $this->task = $this->task->fresh();
        $this->notice = 'Saved for this series.';
        $this->dispatch('task-updated');
    }

    public function stop(): void
    {
        $this->authorize('update', $this->task);

        app(RecurrenceEditor::class)->stop($this->task);

        $this->task = $this->task->fresh();
        $this->notice = 'This task will not repeat.';
        $this->dispatch('task-updated');
    }

    public function useForSeries(): void
    {
        $this->authorize('update', $this->task);

        app(RecurrenceEditor::class)->useForSeries($this->task);

        $this->notice = 'The next occurrences will start from these details.';
    }

    public function render(): View
    {
        return view('livewire.tasks.task-repeat');
    }
}
