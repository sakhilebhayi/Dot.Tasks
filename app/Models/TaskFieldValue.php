<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskFieldValue extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['task_id', 'task_field_id', 'value'];
}
