<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** When something happens to a task, and it fits, do this. */
class TaskRule extends Model
{
    protected $fillable = ['team_id', 'task_list_id', 'created_by', 'name', 'trigger', 'match', 'conditions', 'actions', 'delay_minutes', 'needs_approval', 'enabled'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'needs_approval' => 'boolean', 'enabled' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id')->withArchived();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskRuleRun::class);
    }
}
