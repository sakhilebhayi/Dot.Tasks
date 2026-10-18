<?php

namespace App\Livewire\Labels;

use App\Models\Label;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Team label management. Every member can read the list; creating, renaming,
 * recolouring and deleting is for admins and the team owner (LabelPolicy).
 * Always works on the user's current team, never on an id from the browser,
 * and a label id from another team is a 404 rather than a 403 so it does not
 * confirm the label exists.
 */
class LabelManager extends Component
{
    public string $name = '';

    public string $color = '#6366f1';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editColor = '#6366f1';

    public function mount(): void
    {
        $this->authorize('viewAny', Label::class);
    }

    #[Computed]
    public function team(): Team
    {
        $team = auth()->user()->currentTeam;
        abort_unless($team, 403, 'No active team selected.');

        return $team;
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', [Label::class, $this->team]);
    }

    /** @return Collection<int, Label> */
    #[Computed]
    public function labels(): Collection
    {
        return Label::where('team_id', $this->team->id)
            ->withCount('tasks')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('create', [Label::class, $this->team]);

        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:'.Label::NAME_MAX, $this->uniqueNameRule()],
            'color' => ['required', Rule::in(Label::PALETTE)],
        ]);

        Label::create([
            'team_id' => $this->team->id,
            'name' => $this->name,
            'color' => $this->color,
        ]);

        $this->reset('name');
        unset($this->labels);
    }

    public function startEdit(int $labelId): void
    {
        $label = $this->ownLabel($labelId);
        $this->authorize('update', $label);

        $this->editingId = $label->id;
        $this->editName = $label->name;
        $this->editColor = $label->color;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        abort_unless($this->editingId, 404);

        $label = $this->ownLabel($this->editingId);
        $this->authorize('update', $label);

        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:'.Label::NAME_MAX, $this->uniqueNameRule($label)],
            'editColor' => ['required', Rule::in(Label::PALETTE)],
        ]);

        $label->update(['name' => $this->editName, 'color' => $this->editColor]);

        $this->cancelEdit();
        unset($this->labels);
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editColor');
        $this->resetErrorBag();
    }

    public function delete(int $labelId): void
    {
        $label = $this->ownLabel($labelId);
        $this->authorize('delete', $label);

        $label->tasks()->detach();
        $label->delete();

        if ($this->editingId === $labelId) {
            $this->cancelEdit();
        }

        unset($this->labels);
    }

    private function ownLabel(int $labelId): Label
    {
        return Label::where('team_id', $this->team->id)->find($labelId) ?? abort(404);
    }

    /** Names are unique within a team, ignoring case; the label being renamed does not clash with itself. */
    private function uniqueNameRule(?Label $ignoring = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoring) {
            $taken = Label::where('team_id', $this->team->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                ->when($ignoring, fn ($q) => $q->where('id', '!=', $ignoring->id))
                ->exists();

            if ($taken) {
                $fail('The team already has a label with that name.');
            }
        };
    }

    public function render(): View
    {
        return view('livewire.labels.label-manager');
    }
}
