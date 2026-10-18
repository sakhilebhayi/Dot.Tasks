<?php

namespace App\Livewire\Tasks;

use App\Models\TaskList;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The current team's archived boards, for the people allowed to bring them
 * back (admins and the owner). Everyone else sees nothing here.
 */
class ArchivedBoards extends Component
{
    /** @return Collection<int, TaskList> */
    #[Computed]
    public function boards(): Collection
    {
        $team = auth()->user()->currentTeam;

        if (! $team) {
            return collect();
        }

        return TaskList::onlyArchived()
            ->where('team_id', $team->id)
            ->withCount('tasks')
            ->orderBy('name')
            ->get()
            ->filter(fn (TaskList $board) => auth()->user()->can('restore', $board))
            ->values();
    }

    public function restoreBoard(int $taskListId): void
    {
        $board = TaskList::onlyArchived()
            ->where('team_id', auth()->user()->currentTeam?->id)
            ->find($taskListId) ?? abort(404);

        $this->authorize('restore', $board);

        $board->unarchive();

        // The dashboard around this component lists live boards too.
        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.tasks.archived-boards');
    }
}
