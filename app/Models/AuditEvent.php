<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What changed how a business runs. Written once, never edited; work on one task is in that task's history instead. */
class AuditEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['team_id', 'user_id', 'action', 'what'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths((int) config('tasks.audit_months')));
    }
}
