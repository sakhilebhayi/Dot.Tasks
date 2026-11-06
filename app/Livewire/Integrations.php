<?php

namespace App\Livewire;

use App\Services\Analytics\AnalyticsClient;
use App\Services\Files\FilesClient;
use App\Services\Notify\NotifyClient;
use Illuminate\View\View;
use Livewire\Component;

/** Which Dot platforms this business reaches. It says what is set up and what last happened, and never that a link works. */
class Integrations extends Component
{
    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        abort_unless($team && auth()->user()->hasTeamPermission($team, 'delete'), 403);
    }

    public function render(): View
    {
        $team = auth()->user()->currentTeam;
        $last = FilesClient::last();

        return view('livewire.integrations', [
            'linked' => filled($team->infodot_team_uuid),
            'teamName' => $team->name,
            'platforms' => [
                ['name' => 'Dot.Notify', 'does' => 'Emails for assignments, mentions and due dates.', 'ready' => app(NotifyClient::class)->enabled(), 'last' => null],
                ['name' => 'Dot.Analytics', 'does' => 'Daily counts, with no names or contents.', 'ready' => app(AnalyticsClient::class)->enabled(), 'last' => null],
                ['name' => 'Dot.Files', 'does' => 'Copies of task files, kept when someone asks.', 'ready' => app(FilesClient::class)->enabled(), 'last' => $last],
            ],
        ]);
    }
}
