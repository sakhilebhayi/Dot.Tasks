<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskView extends Model
{
    protected $fillable = ['user_id', 'task_list_id', 'name', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
