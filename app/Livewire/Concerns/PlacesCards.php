<?php

namespace App\Livewire\Concerns;

use App\Events\TaskBoardUpdated;
use App\Models\Task;
use App\Models\TaskList;
use App\Services\TaskEditor;
use App\Services\TaskPlacement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Where a card lands in any view that groups cards by status, person or priority. */
trait PlacesCards
{
    /** What a lane in this view stands for: 'assignee', 'priority', or '' when it has none. */
    abstract protected function laneKind(): string;

    /**
     * Where a card landed, by hand or by keyboard. A refusal leaves the card where it was and says why: the browser has
     * already shown the move, so this has to answer rather than fail. $from is the column the person saw it in.
     *
     * @return array{ok: bool, message?: string}
     */
    public function place(int $taskId, string $status, ?int $beforeId = null, ?string $from = null, ?string $lane = null): array
    {
        $this->resetErrorBag('status');

        $column = $this->column($status);
        if (! $column) {
            throw ValidationException::withMessages(['status' => 'That column is not on this board.']);
        }

        $task = $this->boardQuery()->find($taskId);
        $this->forgetTasks();

        if (! $task) {
            return $this->refuse('That card is no longer on this board.');
        }

        $this->authorize('move', $task);

        if ($from !== null && (string) (ctype_digit($from) ? $task->status_id : $task->status) !== $from) {
            return $this->refuse('Someone else moved that card, so here is where it is now.');
        }

        try {
            DB::transaction(function () use ($task, $column, $beforeId, $lane) {
                $this->applyLane($task, $lane);
                app(TaskPlacement::class)->place($task, $column, $beforeId);
            });
        } catch (ValidationException $refused) {
            return $this->refuse($refused->validator->errors()->first());
        }

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();

        return ['ok' => true];
    }

    /**
     * Dropped on another board in the sidebar. Allowed where this person could make a task there, and only on this team.
     *
     * @return array{ok: bool, message?: string}
     */
    public function moveToBoard(int $taskId, int $boardId): array
    {
        $task = $this->boardQuery()->find($taskId);
        $board = TaskList::where('team_id', $this->taskList->team_id)->find($boardId);

        if (! $task || ! $board) {
            return $this->refuse('That card or board is no longer here.');
        }

        $this->authorize('move', $task);

        if (! auth()->user()->can('create', [Task::class, $board])) {
            return $this->refuse('You cannot add tasks to that board.');
        }

        app(TaskPlacement::class)->moveToBoard($task, $board);

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();
        broadcast(new TaskBoardUpdated($board))->toOthers();
        $this->forgetTasks();

        return ['ok' => true];
    }

    /** @return array{ok: bool, message: string} */
    private function refuse(string $message): array
    {
        $this->addError('status', $message);

        return ['ok' => false, 'message' => $message];
    }

    /** A card dropped in another lane takes on what that lane stands for. */
    private function applyLane(Task $task, ?string $lane): void
    {
        if ($lane === null || $this->laneKind() === '') {
            return;
        }

        if ($this->laneKind() === 'priority') {
            app(TaskEditor::class)->set($task, 'priority', $lane);

            return;
        }

        $this->authorize('assign', $task);
        $person = $lane === 'none' ? null : $this->members->firstWhere('id', (int) $lane);
        if ($lane !== 'none' && ! $person) {
            throw ValidationException::withMessages(['status' => 'That person is not on this team.']);
        }

        app(TaskEditor::class)->assign($task, $person, auth()->user());
    }
}
