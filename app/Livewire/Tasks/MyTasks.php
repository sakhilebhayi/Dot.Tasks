<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Livewire\Concerns\FiltersTasks;
use App\Models\Label;
use App\Models\Task;
use App\Models\Team;
use App\Services\TaskStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything assigned to the signed-in user across every board of their
 * current team, most urgent first: priority rank, then due date, undated last.
 * Same URL-bound filters as the board, minus the assignee (it is always you).
 */
class MyTasks extends Component
{
    use FiltersTasks;

    #[Url(as: 'done', except: false)]
    public bool $showDone = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->currentTeam, 403, 'No active team selected.');
    }

    protected function filterTeamId(): int
    {
        return $this->team()->id;
    }

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->applyTaskFilters(Task::forTeam($this->team()))
            ->where('tasks.assignee_id', auth()->id())
            ->when(! $this->showDone, fn ($q) => $q->where('tasks.status', '!=', 'done'))
            ->with(['taskList.team', 'labels'])
            ->orderByPriorityRank()
            ->orderByDueDate()
            ->orderBy('tasks.id')
            ->get();
    }

    /** @return Collection<int, Label> */
    #[Computed]
    public function labels(): Collection
    {
        return Label::where('team_id', $this->team()->id)->orderBy('name')->get();
    }

    #[Computed]
    public function filtersActive(): bool
    {
        return $this->hasTaskFilters();
    }

    public function clearFilters(): void
    {
        $this->resetTaskFilters();
        unset($this->tasks, $this->filtersActive);
    }

    /** Mark a task done from the list, exactly as dragging it to Done on its board would. */
    public function completeTask(int $taskId): void
    {
        $task = Task::forTeam($this->team())->find($taskId) ?? abort(404);
        $this->authorize('move', $task);

        app(TaskStatusService::class)->move($task, 'done');

        broadcast(new TaskBoardUpdated($task->taskList))->toOthers();
        unset($this->tasks);
    }

    #[On('task-updated')]
    public function refresh(): void
    {
        unset($this->tasks);
    }

    public function render(): View
    {
        return view('livewire.tasks.my-tasks');
    }
}
