<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A column on one board. Its category says what it means to be there; its name is the board's own. */
class TaskStatus extends Model
{
    public const CATEGORIES = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'Review', 'done' => 'Done'];

    public const DEFAULTS = ['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'];

    protected $fillable = ['task_list_id', 'name', 'category', 'sort_order'];

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }
}
