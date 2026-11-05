<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskFileController extends Controller
{
    /** Always a download: a file somebody uploaded never runs as a page on this site. */
    public function show(Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_unless($attachment->task_id === $task->id, 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
