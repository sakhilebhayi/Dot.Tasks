<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a task's history. Rows are only ever added. */
class TaskActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'user_id', 'kind', 'summary'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
