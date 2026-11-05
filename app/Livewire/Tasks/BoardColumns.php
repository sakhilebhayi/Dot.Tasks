<?php

namespace App\Livewire\Tasks;

use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Services\BoardColumnEditor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** Name, order and add a board's columns. */
class BoardColumns extends Component
{
    public TaskList $taskList;

    public bool $open = false;

    public string $newName = '';

    public string $newCategory = 'in_progress';

    /** Bumped on every edit, so a refused one puts the control back. */
    public int $edits = 0;

    /** @return Collection<int, TaskStatus> */
    #[Computed]
    public function columns(): Collection
    {
        return $this->taskList->columns()->get();
    }

    private function column(int $id): TaskStatus
    {
        $this->authorize('update', $this->taskList);
        $this->edits++;

        return $this->columns->firstWhere('id', $id) ?? abort(404);
    }

    private function changed(): void
    {
        unset($this->columns);
        $this->taskList->unsetRelation('columns');
        $this->dispatch('task-updated');
    }

    public function add(): void
    {
        $this->authorize('update', $this->taskList);

        app(BoardColumnEditor::class)->add($this->taskList, $this->newName, $this->newCategory);

        $this->newName = '';
        $this->changed();
    }

    public function rename(int $id, string $name): void
    {
        app(BoardColumnEditor::class)->rename($this->column($id), $name);
        $this->changed();
    }

    public function recategorize(int $id, string $category): void
    {
        app(BoardColumnEditor::class)->recategorize($this->column($id), $category);
        $this->changed();
    }

    public function placeColumn(int $id, ?int $beforeId = null): void
    {
        app(BoardColumnEditor::class)->place($this->column($id), $beforeId);
        $this->changed();
    }

    public function remove(int $id): void
    {
        app(BoardColumnEditor::class)->remove($this->column($id));
        $this->changed();
    }

    public function render(): View
    {
        return view('livewire.tasks.board-columns');
    }
}
