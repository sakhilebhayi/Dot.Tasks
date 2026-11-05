<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use Illuminate\Support\Facades\DB;

/** A task's subtasks and checklist. Callers authorize first. */
class TaskSteps
{
    public function __construct(private readonly TaskHistory $history) {}

    public function addSubtask(Task $task, string $title): Task
    {
        $sub = Task::create([
            'task_list_id' => $task->task_list_id,
            'parent_id' => $task->id,
            'title' => trim($title),
            'status' => 'todo',
            'priority' => 'medium',
            'creator_id' => auth()->id(),
            'sort_order' => (int) $task->subtasks()->max('sort_order') + 1,
        ]);
        $this->history->record($task, 'subtask', 'Added the subtask '.$sub->title);

        return $sub;
    }

    public function addChecklistItem(Task $task, string $title): TaskChecklistItem
    {
        return $task->checklistItems()->create([
            'title' => trim($title),
            'sort_order' => (int) $task->checklistItems()->max('sort_order') + 1,
        ]);
    }

    public function toggleChecklistItem(TaskChecklistItem $item): void
    {
        $item->update($item->done_at ? ['done_at' => null, 'done_by' => null] : ['done_at' => now(), 'done_by' => auth()->id()]);
    }

    /** A checklist line that turned out to need more: it becomes a subtask, and leaves the checklist. */
    public function promote(TaskChecklistItem $item): Task
    {
        return DB::transaction(function () use ($item) {
            $sub = $this->addSubtask($item->task, $item->title);
            if ($item->done_at) {
                $sub->update(['status' => 'done', 'completed_at' => $item->done_at, 'completed_by' => $item->done_by]);
            }
            $item->delete();

            return $sub;
        });
    }

    /** A new task beside the original, with its details, labels, subtasks and checklist. Done things come back undone. */
    public function duplicate(Task $task): Task
    {
        return DB::transaction(function () use ($task) {
            $copy = $task->replicate(['completed_at', 'completed_by', 'completion_note', 'escalated_at', 'recurrence_parent_id', 'recurrence_source_id', 'archived_at', 'actual_minutes']);
            $copy->title = $task->title.' (copy)';
            $copy->status = 'todo';
            $copy->creator_id = auth()->id();
            $copy->save();

            $copy->labels()->sync($task->labels->pluck('id'));
            $copy->collaborators()->sync($task->collaborators->pluck('id'));

            foreach ($task->subtasks as $sub) {
                $inner = $sub->replicate(['completed_at', 'completed_by', 'completion_note', 'archived_at', 'actual_minutes']);
                $inner->parent_id = $copy->id;
                $inner->status = 'todo';
                $inner->save();
            }

            foreach ($task->checklistItems as $item) {
                $copy->checklistItems()->create(['title' => $item->title, 'sort_order' => $item->sort_order]);
            }

            $this->history->record($copy, 'created', 'Made as a copy of '.$task->title);

            return $copy;
        });
    }
}
