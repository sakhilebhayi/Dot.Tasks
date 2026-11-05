<?php

namespace App\Livewire\Tasks;

use App\Models\Label;
use App\Models\TaskField;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** The custom fields of a team. Admins decide which exist, as with labels. */
class ManageFields extends Component
{
    public string $name = '';

    public string $type = 'text';

    /** One choice a line. */
    public string $choices = '';

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    /** @return Collection<int, TaskField> */
    #[Computed]
    public function fields(): Collection
    {
        return TaskField::where('team_id', $this->team()->id)->orderBy('sort_order')->orderBy('id')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', [Label::class, $this->team()]);
    }

    public function add(): void
    {
        abort_unless($this->canManage, 403);

        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('task_fields', 'name')->where('team_id', $this->team()->id)],
            'type' => ['required', Rule::in(array_keys(TaskField::TYPES))],
        ], [], ['name' => 'field name']);

        $options = $this->type === 'select'
            ? collect(preg_split('/\R/', $this->choices))->map(fn (string $line) => trim($line))->filter()->unique()->values()->all()
            : null;

        if ($this->type === 'select' && $options === []) {
            $this->addError('choices', 'List the choices, one a line.');

            return;
        }

        TaskField::create([
            'team_id' => $this->team()->id, 'name' => $this->name, 'type' => $this->type, 'options' => $options,
            'sort_order' => (int) TaskField::where('team_id', $this->team()->id)->max('sort_order') + 1,
        ]);

        $this->reset('name', 'type', 'choices');
        unset($this->fields);
    }

    public function remove(int $id): void
    {
        abort_unless($this->canManage, 403);

        TaskField::where('team_id', $this->team()->id)->whereKey($id)->delete();

        unset($this->fields);
    }

    public function render(): View
    {
        return view('livewire.tasks.manage-fields');
    }
}
