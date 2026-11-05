<?php

namespace App\Livewire\Teams;

use App\Models\Team;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/** One time zone for a business, which decides when today ends for everything on it. */
class UpdateTeamClock extends Component
{
    public Team $team;

    public string $timezone = '';

    public bool $saved = false;

    public function mount(Team $team): void
    {
        $this->authorize('view', $team);

        $this->team = $team;
        $this->timezone = $team->timezone ?? config('app.timezone');
    }

    public function save(): void
    {
        $this->authorize('update', $this->team);

        $this->validate(['timezone' => ['required', Rule::in(timezone_identifiers_list())]]);

        $this->team->forceFill(['timezone' => $this->timezone])->save();
        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.teams.update-team-clock', ['zones' => timezone_identifiers_list()]);
    }
}
