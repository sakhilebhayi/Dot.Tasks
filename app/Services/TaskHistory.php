<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use Illuminate\Support\Str;

/** Writes a task's history. Plain sentences, because this is what people read to find out what happened. */
class TaskHistory
{
    /** Who is really behind the next lines, when a rule is acting for someone. */
    private static ?string $speaker = null;

    public static function speakingFor(?string $speaker): void
    {
        self::$speaker = $speaker;
    }

    public function record(Task $task, string $kind, string $summary): void
    {
        $summary = self::$speaker ? self::$speaker.': '.$summary : $summary;

        TaskActivity::create([
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'kind' => $kind,
            'summary' => Str::limit($summary, 290),
        ]);
    }
}
