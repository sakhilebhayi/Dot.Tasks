<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Support\Str;

class AuditLog
{
    /** Who did it is the person signed in; a scheduled run has nobody. */
    public static function record(int|Team $team, string $action, string $what): void
    {
        AuditEvent::create([
            'team_id' => $team instanceof Team ? $team->id : $team,
            'user_id' => auth()->id(),
            'action' => $action,
            'what' => Str::limit($what, 290),
        ]);
    }
}
