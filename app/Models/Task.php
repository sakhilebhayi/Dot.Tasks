<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Scopes\NotArchivedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use Archivable;
    use SoftDeletes;

    /** @var list<string> Allowed values of tasks.status (mirrors the column enum). */
    public const STATUSES = ['todo', 'in_progress', 'review', 'done'];

    /** @var list<string> Allowed values of tasks.priority, lowest to highest (mirrors the column enum). */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'task_list_id', 'assignee_id', 'parent_id',
        'title', 'description', 'status', 'priority', 'due_date',
        'estimated_minutes', 'sort_order', 'escalated_at',
        'recurrence_type', 'recurrence_interval', 'recurrence_anchor', 'recurrence_parent_id',
        'creator_id',
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

    /**
     * Case-insensitive text match on title and description. The text is
     * matched literally: a % or _ typed into the search box is not a wildcard.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeSearchText(Builder $query, string $text): void
    {
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($text))).'%';

        $query->where(function (Builder $match) use ($needle) {
            $match->whereRaw("LOWER({$this->qualifyColumn('title')}) LIKE ? ESCAPE '\\'", [$needle])
                ->orWhereRaw("LOWER({$this->qualifyColumn('description')}) LIKE ? ESCAPE '\\'", [$needle]);
        });
    }

    /**
     * 'overdue' and 'today' mean not done yet; 'none' is simply no due date.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeDueState(Builder $query, string $state): void
    {
        match ($state) {
            'overdue' => $query->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', Carbon::today())->where('tasks.status', '!=', 'done'),
            'today' => $query->whereDate('tasks.due_date', Carbon::today())->where('tasks.status', '!=', 'done'),
            'none' => $query->whereNull('tasks.due_date'),
            default => null,
        };
    }

    /**
     * Soonest due date first, tasks with no due date last. Portable CASE
     * rather than NULLS LAST, which SQLite only learned recently.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOrderByDueDate(Builder $query): void
    {
        $query->orderByRaw("CASE WHEN {$query->getModel()->qualifyColumn('due_date')} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($query->getModel()->qualifyColumn('due_date'));
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * The tasks that must be done before this one can be. Archived blockers
     * stay in the list (they still block); deleted ones drop out.
     */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'blocked_by_id')
            ->withoutGlobalScope(NotArchivedScope::class);
    }

    /** The tasks waiting on this one. */
    public function blocking(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'blocked_by_id', 'task_id')
            ->withoutGlobalScope(NotArchivedScope::class);
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
