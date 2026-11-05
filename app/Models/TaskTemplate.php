<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A shape of work a team repeats. */
class TaskTemplate extends Model
{
    protected $fillable = ['team_id', 'created_by', 'name', 'title', 'description', 'priority', 'assignee_id', 'due_in_days', 'estimated_minutes', 'subtasks', 'checklist', 'label_ids', 'sort_order'];

    protected function casts(): array
    {
        return ['subtasks' => 'array', 'checklist' => 'array', 'label_ids' => 'array'];
    }
}
