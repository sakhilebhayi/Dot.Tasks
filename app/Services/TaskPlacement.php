<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where a card sits. A column keeps plain positions 1, 2, 3, and a drop renumbers it inside one transaction, so a
 * refresh, another person or a failed step can never leave two truths. Callers authorize first.
 */
class TaskPlacement
{
    public function __construct(
        private readonly TaskStatusService $status,
        private readonly TaskHistory $history,
    ) {}

    /** Into a column, before another card, or at the foot of it. A refused status change moves nothing. */
    public function place(Task $task, TaskStatus $column, ?int $beforeId = null): void
    {
        DB::transaction(function () use ($task, $column, $beforeId) {
            if ($task->status_id !== $column->id) {
                $this->status->move($task, $column);
            }

            $this->seat($task, $beforeId, Task::where('task_list_id', $task->task_list_id)->whereNull('parent_id')->where('status_id', $column->id));
        });
    }

    /** To another board of the same team, at the foot of its column, with its subtasks. Callers check both boards. */
    public function moveToBoard(Task $task, TaskList $board): void
    {
        DB::transaction(function () use ($task, $board) {
            $sort = (int) Task::where('task_list_id', $board->id)->whereNull('parent_id')->where('status', $task->status)->max('sort_order') + 1;
            $board->unsetRelation('columns');
            $task->update(['task_list_id' => $board->id, 'sort_order' => $sort]);

            for ($parents = [$task->id]; $parents !== [];) {
                $children = Task::withArchived()->whereIn('parent_id', $parents)->pluck('id')->all();
                Task::withArchived()->whereIn('id', $children)->get()->each->update(['task_list_id' => $board->id]);
                $parents = $children;
            }

            $this->history->record($task, 'moved', 'Moved it to '.($board->is_inbox ? 'an Inbox' : $board->name));
        });
    }

    public function placeSubtask(Task $subtask, ?int $beforeId = null): void
    {
        DB::transaction(fn () => $this->seat($subtask, $beforeId, Task::where('parent_id', $subtask->parent_id)));
    }

    /** @param  Builder<Task>  $siblings */
    private function seat(Task $task, ?int $beforeId, Builder $siblings): void
    {
        $order = $siblings->lockForUpdate()->orderBy('sort_order')->orderBy('id')->pluck('id')
            ->reject(fn (int $id) => $id === $task->id)->values();

        // A card that left while this one was in the air: the foot of the column is still a true place.
        $at = $beforeId === null ? false : $order->search($beforeId);
        $order->splice($at === false ? $order->count() : $at, 0, [$task->id]);

        // Positions are not an edit, so they go straight to the column and leave updated_at alone.
        foreach ($order as $index => $id) {
            DB::table('tasks')->where('id', $id)->where('sort_order', '!=', $index + 1)->update(['sort_order' => $index + 1]);
        }
    }
}
