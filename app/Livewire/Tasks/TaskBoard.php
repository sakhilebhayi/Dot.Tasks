<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\PlacesCards;
use App\Livewire\Concerns\ShowsBoardTasks;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\TaskArchiveService;
use App\Services\TaskHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class TaskBoard extends Component
{
    use PlacesCards;
    use ShowsBoardTasks;

    /** '' for the live board, 'archived' for the archived list. */
    #[Url(except: '')]
    public string $show = '';

    /** '' for one rack, or 'assignee' or 'priority' for a lane each. */
    #[Url(except: '')]
    public string $lanes = '';

    public const COLUMNS = [
        'todo' => 'To Do',
        'in_progress' => 'In Progress',
        'review' => 'Review',
        'done' => 'Done',
    ];

    #[Computed]
    public function showingArchived(): bool
    {
        return $this->show === 'archived';
    }

    #[Computed]
    public function tasksByStatus(): array
    {
        $tasks = $this->filteredQuery()
            ->with(['assignee', 'labels', 'subtasks', 'blockedBy'])
            ->orderBy('tasks.sort_order')
            ->orderBy('tasks.id')
            ->get()
            ->groupBy('status_id');

        return $this->columns->mapWithKeys(fn (TaskStatus $column) => [$column->id => $tasks->get($column->id, collect())])->all();
    }

    /**
     * The rows of the board: one with no name, or one per person or priority.
     *
     * @return list<array{key: string, name: string, columns: array<string, Collection<int, Task>>, count: int}>
     */
    #[Computed]
    public function swimlanes(): array
    {
        $all = collect($this->tasksByStatus)->flatten(1);

        $lanes = match ($this->lanes) {
            'assignee' => $this->members->map(fn (User $person) => ['key' => (string) $person->id, 'name' => $person->name])
                ->push(['key' => 'none', 'name' => 'Unassigned'])
                ->filter(fn (array $lane) => $this->canSeeLane($lane['key'], $all))->values()->all(),
            'priority' => array_map(fn (string $p) => ['key' => $p, 'name' => ucfirst($p)], array_reverse(Task::PRIORITIES)),
            default => [['key' => '', 'name' => '']],
        };

        return array_map(function (array $lane) {
            $columns = collect($this->tasksByStatus)->map(fn (Collection $tasks) => $tasks
                ->filter(fn (Task $task) => $this->inLane($task, $lane['key']))->values())->all();

            return $lane + ['columns' => $columns, 'count' => collect($columns)->sum(fn (Collection $tasks) => $tasks->count())];
        }, $lanes);
    }

    /** Everybody with work on this board, and whoever a person might want to hand work to if the board is small. */
    private function canSeeLane(string $key, Collection $tasks): bool
    {
        return $this->members->count() <= 8 || $tasks->contains(fn (Task $task) => $this->inLane($task, $key));
    }

    private function inLane(Task $task, string $key): bool
    {
        return match ($this->lanes) {
            'assignee' => (string) ($task->assignee_id ?? 'none') === $key,
            'priority' => $task->priority === $key,
            default => true,
        };
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

    /** Add a card at the foot of a column by typing its title. */
    public function addHere(string $status, string $title): void
    {
        $this->authorize('create', [Task::class, $this->taskList]);

        Validator::make(['title' => trim($title)], ['title' => ['required', 'string', 'max:255']])->validate();

        $column = $this->column($status);
        if (! $column || $column->category === 'done') {
            throw ValidationException::withMessages(['status' => 'A new card cannot start in that column.']);
        }

        $task = Task::create([
            'task_list_id' => $this->taskList->id,
            'creator_id' => auth()->id(),
            'title' => trim($title),
            'status' => $column->category,
            'status_id' => $column->id,
            'priority' => 'medium',
            'sort_order' => (int) $this->taskList->tasks()->where('status_id', $column->id)->max('sort_order') + 1,
        ]);
        app(TaskHistory::class)->record($task, 'created', 'Created it');

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetTasks();
    }

    /** Bring an archived task (and the subtasks archived with it) back to the board. */
    public function restoreTask(int $taskId): void
    {
        $task = $this->archivedQuery()->find($taskId) ?? abort(404);
        $this->authorize('restore', $task);

        app(TaskArchiveService::class)->restore($task);

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetTasks();
    }

    /** Delete an archived task: a soft delete, with its subtasks. Admins and the owner only. */
    public function deleteTask(int $taskId, bool $confirmed = false): void
    {
        $task = $this->archivedQuery()->find($taskId) ?? abort(404);
        $this->authorize('delete', $task);

        app(TaskArchiveService::class)->delete($task, $confirmed);

        $this->forgetTasks();
    }

    protected function forgetTasks(): void
    {
        unset($this->columns, $this->tasksByStatus, $this->swimlanes, $this->archivedTasks, $this->filtersActive);
    }

    protected function ownSettings(): array
    {
        return ['lanes' => $this->lanes];
    }

    protected function laneKind(): string
    {
        return $this->lanes;
    }

    public function render(): View
    {
        return view('livewire.tasks.task-board');
    }
}
