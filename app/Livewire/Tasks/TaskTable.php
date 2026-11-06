<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\PlacesCards;
use App\Livewire\Concerns\ShowsBoardTasks;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Services\TaskArchiveService;
use App\Services\TaskEditor;
use App\Services\TaskStatusService;
use App\Support\Today;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The same tasks as the board, as a list: edited in the row, grouped, sorted, and worked on in batches. */
class TaskTable extends Component
{
    use PlacesCards;
    use ShowsBoardTasks;

    public const GROUPS = ['status' => 'Status', 'assignee' => 'Person', 'priority' => 'Priority', 'due' => 'Due date', 'none' => 'No groups'];

    public const SORTS = ['manual' => 'Your order', 'due' => 'Due date', 'priority' => 'Priority', 'title' => 'Title', 'updated' => 'Last changed'];

    public const COLUMNS = ['status' => 'Status', 'assignee' => 'Person', 'priority' => 'Priority', 'due' => 'Due', 'labels' => 'Labels', 'estimate' => 'Estimate'];

    private const SHOWN = ['status', 'assignee', 'priority', 'due'];

    #[Url(except: 'status')]
    public string $group = 'status';

    #[Url(except: 'manual')]
    public string $sort = 'manual';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    /** Comma-separated: which columns this person wants. */
    #[Url(as: 'cols', except: '')]
    public string $cols = '';

    /** @var list<int|string> */
    public array $selected = [];

    /** Bumped on every edit, so a refused change puts the control back. */
    public int $edits = 0;

    public string $notice = '';

    protected function forgetTasks(): void
    {
        unset($this->columns, $this->tasks, $this->groups, $this->filtersActive);
    }

    protected function ownSettings(): array
    {
        return ['view' => 'list', 'group' => $this->group === 'status' ? '' : $this->group, 'sort' => $this->sort === 'manual' ? '' : $this->sort, 'dir' => $this->dir === 'asc' ? '' : $this->dir, 'cols' => $this->cols];
    }

    protected function laneKind(): string
    {
        return in_array($this->group, ['assignee', 'priority'], true) ? $this->group : '';
    }

    #[Computed]
    public function groupBy(): string
    {
        return array_key_exists($this->group, self::GROUPS) ? $this->group : 'status';
    }

    #[Computed]
    public function sortBy(): string
    {
        return array_key_exists($this->sort, self::SORTS) ? $this->sort : 'manual';
    }

    /** @return array<string, string> the fixed columns, then this team's custom fields */
    #[Computed]
    public function allColumns(): array
    {
        return self::COLUMNS + $this->customFields->mapWithKeys(fn ($field) => ['f'.$field->id => $field->name])->all();
    }

    /** @return list<string> */
    #[Computed]
    public function shown(): array
    {
        $picked = $this->cols === '' ? self::SHOWN : explode(',', $this->cols);

        return array_values(array_intersect(array_keys($this->allColumns), $picked));
    }

    public function toggleColumn(string $column): void
    {
        if (! array_key_exists($column, $this->allColumns)) {
            return;
        }

        $shown = in_array($column, $this->shown, true) ? array_diff($this->shown, [$column]) : [...$this->shown, $column];
        $this->cols = implode(',', $shown) ?: 'none';
        unset($this->shown);
    }

    /** Sort by a column. A second press turns it around, and a third goes back to the person's own order. */
    public function sortOn(string $sort): void
    {
        if (! array_key_exists($sort, self::SORTS)) {
            return;
        }

        [$this->sort, $this->dir] = match (true) {
            $this->sort !== $sort => [$sort, 'asc'],
            $this->dir === 'asc' => [$sort, 'desc'],
            default => ['manual', 'asc'],
        };
        $this->forgetTasks();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        $tasks = $this->sorted($this->filteredQuery())->with(['assignee', 'labels', 'subtasks', 'blockedBy', 'fieldValues'])->get();

        // Every row asks the same board whether this person may edit it.
        return $tasks->each(fn (Task $task) => $task->setRelation('taskList', $this->taskList));
    }

    /** @param  Builder<Task>  $query */
    private function sorted(Builder $query): Builder
    {
        $dir = $this->dir === 'desc' ? 'desc' : 'asc';

        return match ($this->sortBy) {
            'due' => $query->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')->orderBy('tasks.due_date', $dir)->orderBy('tasks.id'),
            'priority' => $query->orderByRaw("CASE tasks.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END {$dir}")->orderBy('tasks.id'),
            'title' => $query->orderBy('tasks.title', $dir),
            'updated' => $query->orderBy('tasks.updated_at', $dir === 'asc' ? 'desc' : 'asc')->orderBy('tasks.id'),
            default => $query->orderBy('tasks.sort_order')->orderBy('tasks.id'),
        };
    }

    /**
     * @return list<array{key: string, name: string, tasks: \Illuminate\Support\Collection<int, Task>}>
     */
    #[Computed]
    public function groups(): array
    {
        $tasks = $this->tasks;

        $groups = match ($this->groupBy) {
            'status' => $this->columns->map(fn (TaskStatus $column) => ['key' => (string) $column->id, 'name' => $column->name, 'category' => $column->category, 'tasks' => $tasks->where('status_id', $column->id)->values()])->all(),
            'priority' => collect(array_reverse(Task::PRIORITIES))->map(fn (string $key) => ['key' => $key, 'name' => ucfirst($key), 'tasks' => $tasks->where('priority', $key)->values()])->all(),
            'assignee' => $this->members->map(fn ($person) => ['key' => (string) $person->id, 'name' => $person->name, 'tasks' => $tasks->where('assignee_id', $person->id)->values()])
                ->push(['key' => 'none', 'name' => 'Unassigned', 'tasks' => $tasks->whereNull('assignee_id')->values()])->all(),
            'due' => $this->dueGroups($tasks),
            default => [['key' => '', 'name' => '', 'tasks' => $tasks->values()]],
        };

        return $this->groupBy === 'none' ? $groups : array_values(array_filter($groups, fn (array $group) => $group['tasks']->isNotEmpty() || $this->canDropInGroups()));
    }

    #[Computed]
    public function canDropInGroups(): bool
    {
        return in_array($this->groupBy, ['status', 'assignee', 'priority'], true) && auth()->user()->can('create', [Task::class, $this->taskList]);
    }

    /** Your own order means something inside a status. Between people or priorities a drop changes that, not the order. */
    #[Computed]
    public function canDrag(): bool
    {
        return $this->canDropInGroups && $this->sortBy === 'manual';
    }

    /** @param  Collection<int, Task>  $tasks */
    private function dueGroups(Collection $tasks): array
    {
        $today = Today::date();
        $buckets = ['overdue' => 'Overdue', 'today' => 'Today', 'week' => 'Next 7 days', 'later' => 'Later', 'none' => 'No due date', 'done' => 'Done'];

        $of = fn (Task $task) => match (true) {
            $task->status === 'done' => 'done',
            $task->due_date === null => 'none',
            $task->due_date->lt($today) => 'overdue',
            $task->due_date->isSameDay($today) => 'today',
            $task->due_date->lte($today->copy()->addDays(7)) => 'week',
            default => 'later',
        };

        return collect($buckets)->map(fn (string $name, string $key) => ['key' => $key, 'name' => $name, 'tasks' => $tasks->filter(fn (Task $task) => $of($task) === $key)->values()])->values()->all();
    }

    /** One value of one row. A refusal says why, and the control goes back to what is true. */
    public function saveCell(int $taskId, string $field, ?string $value): void
    {
        $this->edits++;
        $this->resetErrorBag();

        $task = $this->boardQuery()->find($taskId) ?? abort(404);
        $this->change($task, $field, $value);

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetTasks();
    }

    private function change(Task $task, string $field, ?string $value): void
    {
        match ($field) {
            'status' => $this->changeStatus($task, (string) $value),
            'assignee' => $this->changeAssignee($task, $value),
            default => $this->changeField($task, $field, $value),
        };
    }

    private function changeStatus(Task $task, string $status): void
    {
        $this->authorize('move', $task);

        app(TaskStatusService::class)->move($task, $this->column($status) ?? abort(422));
    }

    private function changeAssignee(Task $task, ?string $id): void
    {
        $this->authorize('assign', $task);

        $person = $id === null || $id === '' ? null : ($this->members->firstWhere('id', (int) $id) ?? abort(403));
        app(TaskEditor::class)->assign($task, $person, auth()->user());
    }

    private function changeField(Task $task, string $field, ?string $value): void
    {
        $this->authorize('update', $task);

        app(TaskEditor::class)->set($task, $field, $value);
    }

    /** @return list<int> */
    #[Computed]
    public function drawnIds(): array
    {
        return collect($this->groups)->flatMap(fn (array $group) => $group['tasks']->take($this->batch)->pluck('id'))->values()->all();
    }

    public function toggleAll(): void
    {
        $ids = $this->drawnIds;
        $this->selected = count($this->selected) === count($ids) ? [] : $ids;
    }

    /**
     * The same change to everything selected. Each task goes through the same checks as one edit, and one that cannot
     * change does not stop the others: the person is told which stayed and why.
     */
    public function bulk(string $action, ?string $value = null): void
    {
        abort_unless(in_array($action, ['status', 'assignee', 'priority', 'due_date', 'archive'], true), 422);

        $tasks = $this->boardQuery()->whereIn('tasks.id', array_map('intval', $this->selected))->get();
        $left = [];

        foreach ($tasks as $task) {
            try {
                DB::transaction(fn () => $action === 'archive' ? $this->archive($task) : $this->change($task, $action, $value));
            } catch (ValidationException $refused) {
                $left[] = '"'.$task->title.'": '.$refused->validator->errors()->first();
            }
        }

        $changed = $tasks->count() - count($left);
        $this->notice = ($changed === 1 ? '1 task changed.' : $changed.' tasks changed.').($left === [] ? '' : ' Stayed as they were: '.implode(' ', $left));
        $this->selected = [];
        $this->edits++;

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        $this->forgetTasks();
    }

    private function archive(Task $task): void
    {
        $this->authorize('archive', $task);

        app(TaskArchiveService::class)->archive($task, true);
    }

    public function render(): View
    {
        return view('livewire.tasks.task-table');
    }
}
