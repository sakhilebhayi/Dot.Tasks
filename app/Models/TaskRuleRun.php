<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a rule fired on a task: what is waiting, what happened, and why not. */
class TaskRuleRun extends Model
{
    public const LABELS = ['waiting' => 'Waiting', 'needs_approval' => 'Needs approval', 'done' => 'Done', 'failed' => 'Failed', 'declined' => 'Declined'];

    protected $fillable = ['task_rule_id', 'task_id', 'event_key', 'status', 'run_at', 'attempts', 'summary', 'error', 'decided_by'];

    protected function casts(): array
    {
        return ['run_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TaskRule::class, 'task_rule_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
