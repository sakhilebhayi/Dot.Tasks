<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardLayout extends Model
{
    protected $fillable = ['team_id', 'user_id', 'view', 'layout'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }
}
