<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\FiltersTasks;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\TaskArchiveService;
use App\Services\TaskEditor;
use App\Services\TaskHistory;
use App\Services\TaskPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    /** '' for one rack, or 'assignee' or 'priority' for a lane each. */
    #[Url(except: '')]
    public string $lanes = '';

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
            ->orderBy('tasks.id')
            ->get()
            ->groupBy('status');

        $columns = [];
        foreach (array_keys(self::COLUMNS) as $status) {
            $columns[$status] = $tasks->get($status, collect());
        }

        return $columns;
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

    public function clearFilters(): void
    {
        $this->resetTaskFilters();
        $this->reset('assignee');
        $this->forgetBoard();
    }

    /** Add a card at the foot of a column by typing its title. */
    public function addHere(string $status, string $title): void
    {
        $this->authorize('create', [Task::class, $this->taskList]);

        Validator::make(['status' => $status, 'title' => trim($title)], [
            'status' => ['required', Rule::in(['todo', 'in_progress', 'review'])],
            'title' => ['required', 'string', 'max:255'],
        ])->validate();

        $task = Task::create([
            'task_list_id' => $this->taskList->id,
            'creator_id' => auth()->id(),
            'title' => trim($title),
            'status' => $status,
            'priority' => 'medium',
            'sort_order' => (int) $this->taskList->tasks()->where('status', $status)->max('sort_order') + 1,
        ]);
        app(TaskHistory::class)->record($task, 'created', 'Created it');

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetBoard();
    }

    /**
     * Where a card landed, by hand or by keyboard. A refusal leaves the card where it was and says why: the browser has
     * already shown the move, so this has to answer rather than fail. $from is the column the person saw it in.
     *
     * @return array{ok: bool, message?: string}
     */
    public function place(int $taskId, string $status, ?int $beforeId = null, ?string $from = null, ?string $lane = null): array
    {
        $this->resetErrorBag('status');

        Validator::make(['status' => $status], ['status' => ['required', Rule::in(Task::STATUSES)]])->validate();

        $task = $this->boardQuery()->find($taskId);
        $this->forgetBoard();

        if (! $task) {
            return $this->refuse('That card is no longer on this board.');
        }

        $this->authorize('move', $task);

        if ($from !== null && $task->status !== $from) {
            return $this->refuse('Someone else moved that card, so here is where it is now.');
        }

        try {
            DB::transaction(function () use ($task, $status, $beforeId, $lane) {
                $this->applyLane($task, $lane);
                app(TaskPlacement::class)->place($task, $status, $beforeId);
            });
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

    /** A card dropped in another lane takes on what that lane stands for. */
    private function applyLane(Task $task, ?string $lane): void
    {
        if ($lane === null || $this->lanes === '') {
            return;
        }

        if ($this->lanes === 'priority') {
            app(TaskEditor::class)->set($task, 'priority', $lane);

            return;
        }

        $this->authorize('assign', $task);
        $person = $lane === 'none' ? null : $this->members->firstWhere('id', (int) $lane);
        if ($lane !== 'none' && ! $person) {
            throw ValidationException::withMessages(['status' => 'That person is not on this team.']);
        }

        if ($task->assignee_id !== $person?->id) {
            $task->update(['assignee_id' => $person?->id]);
            app(TaskHistory::class)->record($task, 'people', $person ? 'Assigned it to '.$person->name : 'Left it unassigned');
        }
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
        unset($this->tasksByStatus, $this->swimlanes, $this->archivedTasks, $this->filtersActive);
    }

    public function render(): View
    {
        return view('livewire.tasks.task-board');
    }
}
