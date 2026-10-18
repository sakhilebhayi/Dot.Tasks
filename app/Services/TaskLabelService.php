<?php

namespace App\Services;

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Validation\ValidationException;

/**
 * Puts labels on tasks. The one rule that matters: a label can only be put on
 * a task of its own team. The ids arrive from the browser, so every one is
 * checked against the team's labels here rather than trusted.
 */
class TaskLabelService
{
    /**
     * Replace a task's labels with exactly these.
     *
     * @param  list<int|string>  $labelIds
     *
     * @throws ValidationException when any id is not a label of the task's team
     */
    public function sync(Task $task, array $labelIds, string $errorKey = 'labels'): void
    {
        $task->labels()->sync($this->ownLabelIds($task->taskList, $labelIds, $errorKey));
    }

    /**
     * Add the label if the task does not have it, take it off if it does.
     *
     * @throws ValidationException
     */
    public function toggle(Task $task, int $labelId): void
    {
        [$id] = $this->ownLabelIds($task->taskList, [$labelId], 'labels');

        $task->labels()->toggle([$id]);
    }

    /**
     * @param  list<int|string>  $labelIds
     * @return list<int>
     *
     * @throws ValidationException
     */
    public function ownLabelIds(TaskList $taskList, array $labelIds, string $errorKey = 'labels'): array
    {
        $wanted = collect($labelIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        $found = Label::where('team_id', $taskList->team_id)
            ->whereIn('id', $wanted)
            ->pluck('id');

        if ($found->count() !== $wanted->count()) {
            throw ValidationException::withMessages([
                $errorKey => 'One or more of those labels do not belong to this team.',
            ]);
        }

        return $wanted->all();
    }
}
