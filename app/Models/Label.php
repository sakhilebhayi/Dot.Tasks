<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Label extends Model
{
    /**
     * The colours a label can take, by name. A fixed set so a board never
     * turns into a rainbow and every colour stays readable on the dark UI.
     * Indigo is first because it is the column's default.
     *
     * @var array<string, string>
     */
    public const PALETTE = [
        'Indigo' => '#6366f1',
        'Blue' => '#3b82f6',
        'Teal' => '#14b8a6',
        'Green' => '#22c55e',
        'Amber' => '#f59e0b',
        'Red' => '#ef4444',
        'Pink' => '#ec4899',
        'Grey' => '#71717a',
    ];

    public const NAME_MAX = 60;

    protected $fillable = ['team_id', 'name', 'color'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_label');
    }
}
