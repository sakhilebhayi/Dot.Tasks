<?php

namespace App\Livewire;

use App\Models\AuditEvent;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/** The business's audit log, for those who answer for it. There is nothing here to edit. */
class AuditTrail extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        abort_unless($team && auth()->user()->hasTeamPermission($team, 'delete'), 403);
    }

    public function render(): View
    {
        return view('livewire.audit-trail', [
            'events' => AuditEvent::with('user')->where('team_id', auth()->user()->currentTeam->id)->latest('id')->paginate(50),
        ]);
    }
}
