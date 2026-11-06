<?php

namespace App\Livewire\Tasks;

use App\Livewire\Concerns\ShowsInBatches;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Services\TaskEditor;
use App\Services\WorkloadCheck;
use App\Support\Today;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Who holds what. Each person is a slot of open work, and a card dragged to someone else is handed over. */
class TeamWork extends Component
{
    use ShowsInBatches;

    public const SHOWS = ['open' => 'All open work', 'overdue' => 'Overdue', 'blocked' => 'Blocked', 'week' => 'Due in 7 days'];

    #[Url(except: '')]
    public string $board = '';

    #[Url(except: 'open')]
    public string $show = 'open';

    public function mount(): void
    {
        $this->batch = 30;
    }

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    /** @return EloquentCollection<int, TaskList> */
    #[Computed]
    public function boards(): EloquentCollection
    {
        return $this->team()->boards()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function members(): Collection
    {
        return $this->team()->allUsers()->sortBy('name')->values();
    }

    /**
     * One slot per person, then the unassigned. The figures count all of their open work; the cards follow the filters.
     *
     * @return list<array{key: string, name: string, open: int, overdue: int, blocked: int, minutes: int, tasks: Collection<int, Task>}>
     */
    #[Computed]
    public function people(): array
    {
        $today = Today::date();
        $boardId = ctype_digit($this->board) && $this->boards->contains('id', (int) $this->board) ? (int) $this->board : null;

        $tasks = Task::forTeam($this->team())
            ->where('tasks.status', '!=', 'done')
            ->whereNull('tasks.parent_id')
            ->when($boardId, fn ($q) => $q->where('tasks.task_list_id', $boardId))
            ->with(['taskList:id,team_id,name,owner_id,is_inbox', 'blockedBy:id,status'])
            ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')->orderBy('tasks.due_date')->orderBy('tasks.id')
            ->get();

        $isLate = fn (Task $task) => $task->due_date !== null && $task->due_date->lt($today);
        $isBlocked = fn (Task $task) => $task->isBlocked();
        $wanted = fn (Task $task) => match ($this->show) {
            'overdue' => $isLate($task),
            'blocked' => $isBlocked($task),
            'week' => $task->due_date !== null && $task->due_date->lte($today->copy()->addDays(7)) && ! $isLate($task),
            default => true,
        };

        $people = max(1, $this->members->count());
        $average = $tasks->whereNotNull('assignee_id')->count() / $people;

        $slot = fn (string $key, string $name, Collection $held) => [
            'key' => $key,
            'name' => $name,
            'open' => $held->count(),
            'overdue' => $held->filter($isLate)->count(),
            'blocked' => $held->filter($isBlocked)->count(),
            'minutes' => (int) $held->sum('estimated_minutes'),
            'tasks' => $held->filter($wanted)->values(),
            'flags' => $key === 'none' ? [] : app(WorkloadCheck::class)->flags($held, $average),
        ];

        return $this->members
            ->map(fn (User $person) => $slot((string) $person->id, $person->name, $tasks->where('assignee_id', $person->id)->values()))
            ->push($slot('none', 'Unassigned', $tasks->whereNull('assignee_id')->values()))
            ->all();
    }

    /**
     * Hand a card to someone else. $from is who the person saw it with.
     *
     * @return array{ok: bool, message?: string}
     */
    public function reassign(int $taskId, string $person, ?string $from = null): array
    {
        $this->resetErrorBag('status');
        unset($this->people);

        $task = Task::forTeam($this->team())->find($taskId);
        if (! $task) {
            return $this->refuse('That card is no longer here.');
        }

        $this->authorize('assign', $task);

        if ((string) ($task->assignee_id ?? 'none') !== $from) {
            return $this->refuse('Someone else moved that card, so here is where it is now.');
        }

        $assignee = $person === 'none' ? null : $this->members->firstWhere('id', (int) $person);
        if ($person !== 'none' && ! $assignee) {
            return $this->refuse('That person is not on this team.');
        }

        app(TaskEditor::class)->assign($task, $assignee, auth()->user());

        return ['ok' => true];
    }

    /** @return array{ok: bool, message: string} */
    private function refuse(string $message): array
    {
        $this->addError('status', $message);

        return ['ok' => false, 'message' => $message];
    }

    #[On('task-created')]
    #[On('task-updated')]
    public function refreshBoard(): void
    {
        unset($this->people);
    }

    public function render(): View
    {
        return view('livewire.tasks.team-work');
    }
}
