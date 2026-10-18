<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\FiltersTasks;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\TaskArchiveService;
use App\Services\TaskStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class TaskBoard extends Component
{
    use FiltersTasks;

    public TaskList $taskList;

    /** 'me', 'none' or a team member's user id. */
    #[Url(except: '')]
    public string $assignee = '';

    /** '' for the live board, 'archived' for the archived list. */
    #[Url(except: '')]
    public string $show = '';

    /** Bumped on every move attempt so a refused one resets each card's "Move to..." select. */
    public int $moveAttempts = 0;

    public const COLUMNS = [
        'todo' => 'To Do',
        'in_progress' => 'In Progress',
        'review' => 'Review',
        'done' => 'Done',
    ];

    public function mount(TaskList $taskList): void
    {
        $this->authorize('view', $taskList);

        $this->taskList = $taskList;
    }

    protected function filterTeamId(): int
    {
        return $this->taskList->team_id;
    }

    #[Computed]
    public function showingArchived(): bool
    {
        return $this->show === 'archived';
    }

    /** The assignee filter as a user id, 0 for "nobody", or null for no filter. */
    private function assigneeFilter(): int|string|null
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

    /** @return Collection<int, User> */
    #[Computed]
    public function members(): Collection
    {
        return $this->taskList->team->allUsers()->sortBy('name')->values();
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
    private function boardQuery(): Builder
    {
        return Task::forTeam($this->taskList->team_id)->where('tasks.task_list_id', $this->taskList->id);
    }

    #[Computed]
    public function tasksByStatus(): array
    {
        $assignee = $this->assigneeFilter();

        $tasks = $this->applyTaskFilters($this->boardQuery())
            ->whereNull('tasks.parent_id')
            ->when($assignee === 'none', fn ($q) => $q->whereNull('tasks.assignee_id'))
            ->when(is_int($assignee), fn ($q) => $q->where('tasks.assignee_id', $assignee))
            ->with(['assignee', 'labels', 'subtasks', 'blockedBy'])
            ->orderBy('tasks.sort_order')
            ->get()
            ->groupBy('status');

        $columns = [];
        foreach (array_keys(self::COLUMNS) as $status) {
            $columns[$status] = $tasks->get($status, collect());
        }

        return $columns;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Task> */
    #[Computed]
    public function archivedTasks()
    {
        return $this->archivedQuery()
            ->with(['labels', 'parent' => fn ($q) => $q->withArchived()])
            ->orderByDesc('tasks.archived_at')
            ->orderByDesc('tasks.id')
            ->get();
    }

    /** @return Builder<Task> */
    private function archivedQuery(): Builder
    {
        return Task::onlyArchived()->forTeam($this->taskList->team_id)->where('tasks.task_list_id', $this->taskList->id);
    }

    public function clearFilters(): void
    {
        $this->resetTaskFilters();
        $this->reset('assignee');
        $this->forgetBoard();
    }

    public function moveTask(int $taskId, string $newStatus): void
    {
        $this->authorize('update', $this->taskList);
        $this->moveAttempts++;
        $this->resetErrorBag('status');

        // A tampered client can send any string here; reject anything that is
        // not a real status instead of letting the enum column 500.
        Validator::make(['newStatus' => $newStatus], [
            'newStatus' => ['required', Rule::in(Task::STATUSES)],
        ])->validate();

        $task = Task::find($taskId) ?? abort(404);
        abort_unless($task->task_list_id === $this->taskList->id, 403);
        $this->authorize('move', $task);

        app(TaskStatusService::class)->move($task, $newStatus);

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetBoard();
    }

    /** Bring an archived task (and the subtasks archived with it) back to the board. */
    public function restoreTask(int $taskId): void
    {
        $task = $this->archivedQuery()->find($taskId) ?? abort(404);
        $this->authorize('restore', $task);

        app(TaskArchiveService::class)->restore($task);

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetBoard();
    }

    /** Delete an archived task: a soft delete, with its subtasks. Admins and the owner only. */
    public function deleteTask(int $taskId, bool $confirmed = false): void
    {
        $task = $this->archivedQuery()->find($taskId) ?? abort(404);
        $this->authorize('delete', $task);

        app(TaskArchiveService::class)->delete($task, $confirmed);

        $this->forgetBoard();
    }

    /** Hide the whole board. Admins only; its tasks are untouched and come back with it. */
    public function archiveBoard(): void
    {
        $this->authorize('archive', $this->taskList);

        $this->taskList->archive();

        $this->redirectRoute('dashboard');
    }

    /**
     * Called from the board view's Echo listener when another viewer's
     * action broadcasts a TaskBoardUpdated event, and when the drawer or the
     * new-task form changes something -- just invalidates the cached computed
     * properties so the next render re-fetches current state.
     */
    #[On('task-created')]
    #[On('task-updated')]
    public function refreshBoard(): void
    {
        $this->forgetBoard();
    }

    private function forgetBoard(): void
    {
        unset($this->tasksByStatus, $this->archivedTasks, $this->filtersActive);
    }

    public function render(): View
    {
        return view('livewire.tasks.task-board');
    }
}
