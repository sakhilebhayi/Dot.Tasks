<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use Illuminate\Support\Str;

/** Writes a task's history. Plain sentences, because this is what people read to find out what happened. */
class TaskHistory
{
    public function record(Task $task, string $kind, string $summary): void
    {
        TaskActivity::create([
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'kind' => $kind,
            'summary' => Str::limit($summary, 290),
        ]);
    }
}
