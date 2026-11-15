<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Services\TaskFinder;
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
        return app(TaskFinder::class)->tasks($this->query, auth()->user()->currentTeam, auth()->user(), self::SHOWN);
    }

    /** @return array{words: string, understood: list<string>, apply: list<callable>} */
    #[Computed]
    public function reading(): array
    {
        return app(TaskFinder::class)->reading($this->query, auth()->user()->currentTeam, auth()->user());
    }

    public function render(): View
    {
        return view('livewire.tasks.find-tasks');
    }
}
