<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Models\Task;
use App\Services\TaskStatusService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Todoist-style filtered views spanning every task list on the current
 * team, rather than one board at a time (TaskBoard) -- "what's actually on
 * my plate today/this week", not "what's in this one list".
 */
class SmartViews extends Component
{
    /** 'today' or 'upcoming'. */
    public string $view = 'today';

    public function mount(string $view = 'today'): void
    {
        abort_unless(in_array($view, ['today', 'upcoming'], true), 404);

        $team = auth()->user()->currentTeam;
        abort_unless($team, 403, 'No active team selected.');

        $this->view = $view;
    }

    /**
     * Every non-done task across the team's task lists whose due_date is
     * today or in the past -- Todoist folds overdue items into "Today"
     * rather than hiding them in a separate view.
     */
    #[Computed]
    public function todayTasks()
    {
        return $this->teamTaskQuery()
            ->whereDate('due_date', '<=', Carbon::today())
            ->orderBy('due_date')
            ->orderByPriorityRank()
            ->get();
    }

    /**
     * Every non-done task due in the next 7 days (excluding today/overdue,
     * which "Today" already covers), grouped by date for display.
     */
    #[Computed]
    public function upcomingTasksByDate()
    {
        $tasks = $this->teamTaskQuery()
            ->whereDate('due_date', '>', Carbon::today())
            ->whereDate('due_date', '<=', Carbon::today()->addDays(7))
            ->orderBy('due_date')
            ->get();

        return $tasks->groupBy(fn (Task $task) => $task->due_date->toDateString());
    }

    /** Mark a task done directly from this view, same as dragging it to "Done" on the board. */
    public function completeTask(int $taskId): void
    {
        $task = Task::find($taskId) ?? abort(404);
        $this->authorize('move', $task);

        app(TaskStatusService::class)->move($task, 'done');

        broadcast(new TaskBoardUpdated($task->taskList))->toOthers();
        unset($this->todayTasks, $this->upcomingTasksByDate);
    }

    private function teamTaskQuery()
    {
        return Task::forTeam(auth()->user()->currentTeam)
            ->whereNotNull('due_date')
            ->where('status', '!=', 'done')
            ->with(['taskList.team', 'assignee', 'labels']);
    }

    public function render(): View
    {
        return view('livewire.tasks.smart-views');
    }
}
