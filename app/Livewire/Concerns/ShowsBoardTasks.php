<?php

namespace App\Livewire\Concerns;

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskField;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Models\TaskView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/** What every view of one board shares: the same tasks, the same filters, and the views a person has saved. */
trait ShowsBoardTasks
{
    use FiltersTasks;

    public TaskList $taskList;

    /** 'me', 'none' or a team member's user id. */
    #[Url(except: '')]
    public string $assignee = '';

    public function mount(TaskList $taskList): void
    {
        $this->authorize('view', $taskList);

        $this->taskList = $taskList;
    }

    /** Clears what this view has worked out, so the next render reads the tasks again. */
    abstract protected function forgetTasks(): void;

    /** @return array<string, string> what this view adds to a saved view */
    abstract protected function ownSettings(): array;

    protected function filterTeamId(): int
    {
        return $this->taskList->team_id;
    }

    /** The assignee filter as a user id, 'none' for nobody, or null for no filter. */
    protected function assigneeFilter(): int|string|null
    {
        if ($this->assignee === 'me') {
            return auth()->id();
        }

        if ($this->assignee === 'none') {
            return 'none';
        }

        // Anyone who is not on this board's team filters nothing in.
        return ctype_digit($this->assignee) && $this->members->contains('id', (int) $this->assignee)
            ? (int) $this->assignee
            : null;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TaskStatus> this board's columns, left to right */
    #[Computed]
    public function columns()
    {
        return $this->taskList->columns()->get();
    }

    /** A column of this board, by its id, or the first of a category. */
    protected function column(string $value): ?TaskStatus
    {
        return ctype_digit($value)
            ? $this->columns->firstWhere('id', (int) $value)
            : $this->columns->firstWhere('category', $value);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function members(): Collection
    {
        return $this->taskList->team->allUsers()->sortBy('name')->values();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TaskField> */
    #[Computed]
    public function customFields()
    {
        return TaskField::where('team_id', $this->taskList->team_id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Label> */
    #[Computed]
    public function labels()
    {
        return Label::where('team_id', $this->taskList->team_id)->orderBy('name')->get();
    }

    #[Computed]
    public function filtersActive(): bool
    {
        return $this->hasTaskFilters() || $this->assigneeFilter() !== null;
    }

    /**
     * This board's tasks of this team, through Task::forTeam like every other
     * team-wide query, so no filter can reach another team's rows.
     *
     * @return Builder<Task>
     */
    protected function boardQuery(): Builder
    {
        return Task::forTeam($this->taskList->team_id)->where('tasks.task_list_id', $this->taskList->id);
    }

    /** @return Builder<Task> the top-level tasks that pass the filters */
    protected function filteredQuery(): Builder
    {
        $assignee = $this->assigneeFilter();

        return $this->applyTaskFilters($this->boardQuery())
            ->whereNull('tasks.parent_id')
            ->when($assignee === 'none', fn ($q) => $q->whereNull('tasks.assignee_id'))
            ->when(is_int($assignee), fn ($q) => $q->where('tasks.assignee_id', $assignee));
    }

    public function clearFilters(): void
    {
        $this->resetTaskFilters();
        $this->reset('assignee');
        $this->forgetTasks();
    }

    /**
     * Called by the 20-second refresh, by Echo when it is set up, and when the panel or a form changes something.
     */
    #[On('task-created')]
    #[On('task-updated')]
    public function refreshBoard(): void
    {
        $this->forgetTasks();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TaskView> this person's views of this board, nobody else's */
    #[Computed]
    public function savedViews()
    {
        return TaskView::where('user_id', auth()->id())->where('task_list_id', $this->taskList->id)->orderBy('name')->get();
    }

    public function saveView(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['viewName' => 'Give the view a name of up to 60 characters.']);
        }

        TaskView::updateOrCreate(
            ['user_id' => auth()->id(), 'task_list_id' => $this->taskList->id, 'name' => $name],
            ['settings' => array_filter([
                'q' => $this->search, 'priority' => $this->priority, 'label' => $this->labelId, 'due' => $this->due, 'assignee' => $this->assignee, 'field' => $this->fieldId, 'value' => $this->fieldValue,
            ] + $this->ownSettings(), fn (string $value) => $value !== '')],
        );

        unset($this->savedViews);
    }

    public function deleteView(int $id): void
    {
        TaskView::where('user_id', auth()->id())->where('task_list_id', $this->taskList->id)->whereKey($id)->delete();

        unset($this->savedViews);
    }
}
