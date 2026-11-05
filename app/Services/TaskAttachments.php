<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Files on a task, kept on the private disk and only ever handed out through the download route. Callers authorize first. */
class TaskAttachments
{
    public const MAX_KB = 25 * 1024;

    public const TYPES = 'pdf,png,jpg,jpeg,gif,webp,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip';

    public function __construct(private readonly TaskHistory $history) {}

    public function store(Task $task, UploadedFile $file, User $by): TaskAttachment
    {
        $team = $task->taskList->team_id;
        $path = $file->storeAs("task-files/{$team}/{$task->id}", Str::uuid().'.'.$file->extension(), 'local');

        $attachment = $task->attachments()->create([
            'user_id' => $by->id,
            'path' => $path,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);

        $this->history->record($task, 'file', 'Attached '.$attachment->name);

        return $attachment;
    }

    public function remove(TaskAttachment $attachment): void
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        $this->history->record($attachment->task, 'file', 'Removed '.$attachment->name);
    }
}
