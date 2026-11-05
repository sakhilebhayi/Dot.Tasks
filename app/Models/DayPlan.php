<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayPlan extends Model
{
    protected $fillable = ['user_id', 'task_id', 'day', 'position'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
