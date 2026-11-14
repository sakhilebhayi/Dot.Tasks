<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Models\TaskList;
use App\Services\TaskFinder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The search in the top bar (the shared dot-ui pill, typed into, with its results under it).
 *
 * It asks the same question as the find palette (Ctrl K, FindTasks): App\Services\TaskFinder. A person types, the dropdown
 * shows the tasks of THIS team (plus their own Inbox) that match, read the same way ("overdue maintenance for James"),
 * and the boards of this team whose name matches. Choosing a task opens the task panel; choosing a board goes to it.
 */
class HeaderSearch extends Component
{
    private const TASKS = 7;

    private const BOARDS = 4;

    public string $query = '';

    /** @return Collection<int, Task> */
    #[Computed]
    public function tasks(): Collection
    {
        return app(TaskFinder::class)->tasks($this->query, auth()->user()?->currentTeam, auth()->user(), self::TASKS);
    }

    /** @return Collection<int, TaskList> */
    #[Computed]
    public function boards(): Collection
    {
        return app(TaskFinder::class)->boards($this->query, auth()->user()?->currentTeam, self::BOARDS);
    }

    /** @return array{words: string, understood: list<string>, apply: list<callable>} */
    #[Computed]
    public function reading(): array
    {
        return app(TaskFinder::class)->reading($this->query, auth()->user()?->currentTeam, auth()->user());
    }

    /** The empty box offers this team's boards (the same list as the sidebar), nothing invented. */
    #[Computed]
    public function allBoards(): Collection
    {
        return auth()->user()?->currentTeam?->boards()->orderBy('sort_order')->orderBy('name')->limit(6)->get(['id', 'team_id', 'name', 'color']) ?? new Collection;
    }

    public function ready(): bool
    {
        return app(TaskFinder::class)->ready($this->query);
    }

    /** Enter in the box: the first task, else the first board. Nothing when nothing matched. */
    public function go()
    {
        $task = $this->tasks->first();
        if ($task) {
            $this->query = '';
            $this->dispatch('open-task', taskId: $task->id);

            return null;
        }

        $board = $this->boards->first();
        if ($board) {
            return $this->redirect(route('task-lists.show', $board), navigate: false);
        }

        return null;
    }

    /** A task was chosen from the list: close the dropdown and let the task panel open (it checks access itself). */
    public function picked(): void
    {
        $this->query = '';
    }

    public function render(): View
    {
        return view('livewire.tasks.header-search');
    }
}
