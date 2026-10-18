<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Services\RemovedTaskService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Removed page: the current team's deleted tasks, with a type filter
 * (tasks / subtasks), a board filter, search and pagination. Every member can
 * read it; restoring is for admins and the owner (TaskPolicy::restoreDeleted).
 *
 * Always works on the user's current team, never on a team or id from the
 * browser: a task id from another company is a 404 so it does not confirm that
 * the task exists, and a board filter that is not this team's matches nothing.
 */
class RemovedTasks extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' (everything), 'task' (top level) or 'subtask'. Anything else is ignored. */
    #[Url(except: '')]
    public string $type = '';

    /** '' (every board) or a board id of this team. Anything else matches nothing. */
    #[Url(except: '')]
    public string $board = '';

    /** What the last restore did, in words. */
    public ?string $notice = null;

    #[Computed]
    public function team(): Team
    {
        $team = auth()->user()->currentTeam;
        abort_unless($team, 403, 'No active team selected.');

        return $team;
    }

    /** Whether restore controls are shown at all (each row is still authorized on its own). */
    #[Computed]
    public function canRestore(): bool
    {
        return auth()->user()->hasTeamPermission($this->team, 'delete');
    }

    /** @return Collection<int, TaskList> */
    #[Computed]
    public function boards(): Collection
    {
        return app(RemovedTaskService::class)->boards($this->team);
    }

    #[Computed]
    public function removedTasks(): LengthAwarePaginator
    {
        $type = in_array($this->type, ['task', 'subtask'], true) ? $this->type : '';

        return app(RemovedTaskService::class)->page($this->team, $type, $this->board, $this->search);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingBoard(): void
    {
        $this->resetPage();
    }

    /** Bring a deleted task back, with the subtasks deleted together with it. */
    public function restore(int $taskId): void
    {
        $service = app(RemovedTaskService::class);

        // Not found for this team (another company's id, a live task, a purged one) is a 404, not a 403.
        $task = $service->find($taskId, $this->team) ?? abort(404);

        $this->authorize('restoreDeleted', $task);

        $this->notice = null;
        $result = $service->restore($task, $this->team, auth()->user());
        $this->notice = $result->message();

        $board = TaskList::find($task->task_list_id);
        if ($board) {
            broadcast(new TaskBoardUpdated($board))->toOthers();
        }

        unset($this->removedTasks);
    }

    public function render(): View
    {
        return view('livewire.tasks.removed-tasks');
    }
}
