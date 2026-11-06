<?php

namespace App\Services\Files;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Services\TaskHistory;
use Illuminate\Support\Facades\Storage;

/** A copy of a task's file kept in the business's Dot.Files workspace. The file stays on the task as well. */
class AttachmentFilings
{
    public function __construct(private readonly FilesClient $files, private readonly TaskHistory $history) {}

    /** @throws FilesRefused */
    public function file(TaskAttachment $attachment): void
    {
        $task = $attachment->task;

        if ($attachment->file_uuid !== null) {
            throw new FilesRefused('This file is already in Dot.Files.');
        }

        $bytes = Storage::disk('local')->get($attachment->path);
        if ($bytes === null) {
            throw new FilesRefused('This file could not be read. Attach it again.');
        }

        $reply = $this->files->store($task->taskList->team, $attachment->name, $bytes, (string) config('services.files.folder'));

        $attachment->update(['file_uuid' => (string) ($reply['uuid'] ?? ''), 'open_url' => $this->sameHost((string) ($reply['open_url'] ?? ''))]);
        $this->history->record($task, 'file', 'Kept '.$attachment->name.' in Dot.Files');
    }

    /** A link from another server is only passed to a person if it goes where it should. */
    private function sameHost(string $url): ?string
    {
        $expected = parse_url((string) config('services.files.url'), PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === $expected ? mb_substr($url, 0, 500) : null;
    }
}
