<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @var list<string> Allowed values of tasks.status (mirrors the column enum). */
    public const STATUSES = ['todo', 'in_progress', 'review', 'done'];

    /** @var list<string> Allowed values of tasks.priority, lowest to highest (mirrors the column enum). */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'task_list_id', 'assignee_id', 'parent_id',
        'title', 'description', 'status', 'priority', 'due_date',
        'estimated_minutes', 'sort_order', 'escalated_at',
        'recurrence_type', 'recurrence_interval', 'recurrence_anchor', 'recurrence_parent_id',
    ];

    protected $casts = [
        'due_date' => 'date',
        'escalated_at' => 'datetime',
        'recurrence_interval' => 'integer',
    ];

    /**
     * Restrict to tasks belonging to a team. Tasks have no team_id of their
     * own -- the tenant is derived from the parent task list -- and there is
     * no global scope, so every query that spans lists must go through this.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeForTeam(Builder $query, Team|int $team): void
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        $query->whereHas('taskList', fn (Builder $list) => $list->where('team_id', $teamId));
    }

    /**
     * Order by priority rank urgent > high > medium > low. A CASE expression
     * rather than ORDER BY priority, which would sort alphabetically
     * (high, low, medium, urgent). Portable across SQLite and Postgres.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOrderByPriorityRank(Builder $query): void
    {
        $query->orderByRaw(
            "CASE {$query->getModel()->qualifyColumn('priority')} WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END"
        );
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('sort_order');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'task_label');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function aiBreakdownLogs(): HasMany
    {
        return $this->hasMany(AiBreakdownLog::class);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_type !== null;
    }

    /** The very first task in this recurring series -- itself, if this task has no parent occurrence. */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'recurrence_parent_id');
    }

    /** Every occurrence spawned from this task (only populated on the series' first task). */
    public function recurrenceOccurrences(): HasMany
    {
        return $this->hasMany(Task::class, 'recurrence_parent_id');
    }
}
