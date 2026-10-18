<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per restore from the Removed page. Append-only: the model refuses
 * to update or delete a row, so the trail cannot be rewritten through the app.
 * `restored_with` counts the subtasks that came back with the record.
 */
class RecordRestoration extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'team_id',
        'user_id',
        'record_type',
        'record_id',
        'record_label',
        'restored_with',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Restoration records are append-only.'));
        static::deleting(fn () => throw new LogicException('Restoration records are append-only.'));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
