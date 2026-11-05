<?php

namespace App\Livewire\Tasks;

use App\Models\TaskList;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Hide a whole board. Admins only; its tasks are untouched and come back with it. */
class ArchiveBoard extends Component
{
    public TaskList $taskList;

    public function archive(): void
    {
        $this->authorize('archive', $this->taskList);

        $this->taskList->archive();

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.tasks.archive-board');
    }
}
