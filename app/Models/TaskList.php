<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskList extends Model
{
    use Archivable;

    protected $fillable = ['team_id', 'owner_id', 'name', 'description', 'color', 'sort_order', 'is_inbox'];

    protected $casts = ['is_inbox' => 'boolean'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sort_order');
    }

    /** This board's columns, left to right. */
    public function columns(): HasMany
    {
        return $this->hasMany(TaskStatus::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The column a task of this category goes in when nobody has said which. */
    public function columnFor(string $category): ?TaskStatus
    {
        return $this->columns->firstWhere('category', $category) ?? $this->columns->first();
    }

    protected static function booted(): void
    {
        static::created(function (TaskList $board) {
            $order = 0;
            foreach (TaskStatus::DEFAULTS as $category => $name) {
                $board->columns()->create(['name' => $name, 'category' => $category, 'sort_order' => ++$order]);
            }
        });
    }
}
