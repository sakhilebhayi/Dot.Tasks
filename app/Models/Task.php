<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Scopes\NotArchivedScope;
use App\Services\Automations;
use App\Support\Today;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'recurrence_type', 'recurrence_interval', 'recurrence_anchor', 'recurrence_days', 'recurrence_until', 'recurrence_limit', 'recurrence_count', 'recurrence_missed', 'recurrence_parent_id', 'recurrence_source_id',
        'creator_id', 'status_id', 'start_date', 'actual_minutes', 'completion_criteria', 'completion_note', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'start_date' => 'date',
        'completed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'recurrence_interval' => 'integer',
        'recurrence_days' => 'array',
        'recurrence_until' => 'date',
        'recurrence_limit' => 'integer',
        'recurrence_count' => 'integer',
    ];

    /** Whatever changed a task, it sits in a column of its own board that matches its status. */
    protected static function booted(): void
    {
        static::created(fn (Task $task) => app(Automations::class)->fire('created', $task, 'created:'.$task->id));

        static::saving(function (Task $task) {
            // However a task came to be done, the reports need to know when.
            if ($task->status === 'done' && $task->completed_at === null) {
                $task->completed_at = now();
            }

            if ($task->status_id && ! $task->isDirty(['task_list_id', 'status', 'status_id'])) {
                return;
            }

            $status = $task->status ?? 'todo';
            $fits = $task->status_id && TaskStatus::whereKey($task->status_id)->where('task_list_id', $task->task_list_id)->where('category', $status)->exists();

            if (! $fits) {
                $task->status_id = TaskList::withArchived()->find($task->task_list_id)?->columnFor($status)?->id;
            }
        });
    }

    /**
     * Restrict to tasks belonging to a team. Tasks have no team_id of their
     * own -- the tenant is derived from the parent task list -- and there is
     * no global scope, so every query that spans lists must go through this.
     * Someone else's Inbox is private, so it never shows here.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeForTeam(Builder $query, Team|int $team): void
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        $query->whereHas('taskList', fn (Builder $list) => $list->where('team_id', $teamId)
            ->where(fn (Builder $mine) => $mine->where('is_inbox', false)->orWhere('owner_id', auth()->id())));
    }

    /** A person's own work: given to them, shared with them, or in their Inbox. forTeam shows nobody else's Inbox. @param Builder<Task> $query */
    public function scopeMine(Builder $query, User $user): void
    {
        $query->where(fn ($q) => $q->where('tasks.assignee_id', $user->id)
            ->orWhereHas('collaborators', fn ($people) => $people->whereKey($user->id))
            ->orWhereHas('taskList', fn ($list) => $list->where('is_inbox', true)));
    }

    /** A team's tasks with nobody signed in: only for rules and reports, and never with anyone's Inbox. @param Builder<Task> $query */
    public function scopeForTeamUnscoped(Builder $query, int $team): void
    {
        $query->whereHas('taskList', fn (Builder $list) => $list->where('team_id', $team)->where('is_inbox', false));
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
            'overdue' => $query->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', Today::date())->where('tasks.status', '!=', 'done'),
            'today' => $query->whereDate('tasks.due_date', Today::date())->where('tasks.status', '!=', 'done'),
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
            ->withPivot('type')
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

    /** People following this task without working on it. */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers');
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_collaborators');
    }

    /** What this task holds in the team's custom fields. */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(TaskFieldValue::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(TaskApproval::class)->latest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderBy('id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderByDesc('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function aiBreakdownLogs(): HasMany
    {
        return $this->hasMany(AiBreakdownLog::class);
    }

    /** Is something still standing between this task and finishing? A link says what it waits for: the other task's start or its finish. */
    public function isBlocked(): bool
    {
        return $this->blockedBy->contains(fn (Task $blocker) => in_array($blocker->pivot->type, ['ff', 'fs'], true)
            ? $blocker->status !== 'done'
            : $blocker->status === 'todo');
    }

    /** Past its date and not finished, by the team's own calendar. */
    public function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->due_date !== null && $this->due_date->lt(Today::date());
    }

    public function isDueToday(): bool
    {
        return $this->due_date !== null && $this->due_date->isSameDay(Today::date());
    }

    public function isDueTomorrow(): bool
    {
        return $this->due_date !== null && $this->due_date->isSameDay(Today::date()->addDay());
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
