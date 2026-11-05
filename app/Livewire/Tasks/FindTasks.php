<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Find any task on this team, from any page. */
class FindTasks extends Component
{
    private const SHOWN = 12;

    public bool $open = false;

    public string $query = '';

    #[On('open-search')]
    public function show(): void
    {
        $this->open = true;
        $this->query = '';
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function results(): Collection
    {
        $team = auth()->user()->currentTeam;
        if (! $team || mb_strlen(trim($this->query)) < 2) {
            return new Collection;
        }

        return Task::forTeam($team)->searchText($this->query)
            ->with('taskList:id,name')
            ->orderByRaw("CASE WHEN tasks.status = 'done' THEN 1 ELSE 0 END")
            ->orderByDesc('tasks.updated_at')
            ->limit(self::SHOWN)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.tasks.find-tasks');
    }
}
