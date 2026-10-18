<?php

namespace App\Livewire\Tasks;

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\Notify\NotifyClient;
use App\Services\TaskLabelService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateTask extends Component
{
    public TaskList $taskList;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|in:todo,in_progress,review,done')]
    public string $status = 'todo';

    #[Validate('required|in:low,medium,high,urgent')]
    public string $priority = 'medium';

    #[Validate('nullable|date')]
    public string $dueDate = '';

    #[Validate('nullable|integer|min:1')]
    public ?int $estimatedMinutes = null;

    #[Validate('nullable|in:daily,weekly,monthly,custom_days')]
    public string $recurrenceType = '';

    #[Validate('required_with:recurrenceType|integer|min:1|max:365')]
    public int $recurrenceInterval = 1;

    #[Validate('required_with:recurrenceType|in:due_date,completion')]
    public string $recurrenceAnchor = 'due_date';

    #[Validate('nullable|integer')]
    public ?int $assigneeId = null;

    /** @var list<int|string> */
    #[Validate(['labelIds' => 'array', 'labelIds.*' => 'integer'])]
    public array $labelIds = [];

    public bool $showForm = false;

    public function mount(TaskList $taskList): void
    {
        $this->authorize('view', $taskList);

        $this->taskList = $taskList;
    }

    #[On('open-create-task')]
    public function open(string $status = 'todo'): void
    {
        $this->status = $status;
        $this->showForm = true;
    }

    /**
     * People the new task can be assigned to: members of the board's team only.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function teamMembers()
    {
        return $this->taskList->team->allUsers()->sortBy('name')->values();
    }

    /** @return Collection<int, Label> */
    #[Computed]
    public function teamLabels()
    {
        return Label::where('team_id', $this->taskList->team_id)->orderBy('name')->get();
    }

    public function save(): void
    {
        $this->authorize('create', [Task::class, $this->taskList]);

        $this->validate();

        // The ids come from the browser: the assignee must be on this team and
        // every label must be one of this team's labels.
        $assignee = $this->assigneeId ? User::find($this->assigneeId) : null;

        if ($this->assigneeId && ! ($assignee && $assignee->belongsToTeam($this->taskList->team))) {
            throw ValidationException::withMessages(['assigneeId' => 'Pick someone who is on this team.']);
        }

        $labelIds = app(TaskLabelService::class)->ownLabelIds($this->taskList, $this->labelIds, 'labelIds');

        $maxOrder = $this->taskList->tasks()
            ->where('status', $this->status)
            ->max('sort_order') ?? -1;

        $task = DB::transaction(function () use ($maxOrder, $assignee, $labelIds) {
            $task = Task::create([
                'task_list_id' => $this->taskList->id,
                'assignee_id' => $assignee?->id,
                'creator_id' => auth()->id(),
                'title' => $this->title,
                'description' => $this->description ?: null,
                'status' => $this->status,
                'priority' => $this->priority,
                'due_date' => $this->dueDate ?: null,
                'estimated_minutes' => $this->estimatedMinutes,
                'sort_order' => $maxOrder + 1,
                'recurrence_type' => $this->recurrenceType ?: null,
                'recurrence_interval' => $this->recurrenceInterval,
                'recurrence_anchor' => $this->recurrenceAnchor,
            ]);

            $task->labels()->sync($labelIds);

            return $task;
        });

        if ($assignee && $assignee->id !== auth()->id()) {
            $assignee->notify(new TaskAssignedNotification($task));
            app(NotifyClient::class)->taskAssigned($task, $assignee, auth()->user());
        }

        $this->resetForm();
        $this->showForm = false;
        $this->dispatch('task-created');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['title', 'description', 'dueDate', 'estimatedMinutes', 'recurrenceType', 'recurrenceInterval', 'recurrenceAnchor', 'assigneeId', 'labelIds']);
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.tasks.create-task');
    }
}
