<x-app-layout title="Team settings">
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Team settings</h1>
                <p>Who is on this team, what they can do, and its time zone.</p>
            </div>
        </div>
        <div>
            @livewire('teams.update-team-name-form', ['team' => $team])

            @livewire('teams.update-team-clock', ['team' => $team])

            @livewire('teams.team-member-manager', ['team' => $team])

            @if (Gate::check('delete', $team) && ! $team->personal_team)
                <x-section-border />

                <div class="mt-10 sm:mt-0">
                    @livewire('teams.delete-team-form', ['team' => $team])
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
