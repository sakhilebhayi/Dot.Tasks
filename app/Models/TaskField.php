<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something a business wants to record on its tasks. */
class TaskField extends Model
{
    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Choice', 'checkbox' => 'Yes or no'];

    protected $fillable = ['team_id', 'name', 'type', 'options', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
