<?php

namespace App\Livewire\Concerns;

use App\Events\TaskBoardUpdated;
use App\Models\Task;
use App\Services\TaskEditor;
use App\Services\TaskPlacement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

        Validator::make(['status' => $status], ['status' => ['required', Rule::in(Task::STATUSES)]])->validate();

        $task = $this->boardQuery()->find($taskId);
        $this->forgetTasks();

        if (! $task) {
            return $this->refuse('That card is no longer on this board.');
        }

        $this->authorize('move', $task);

        if ($from !== null && $task->status !== $from) {
            return $this->refuse('Someone else moved that card, so here is where it is now.');
        }

        try {
            DB::transaction(function () use ($task, $status, $beforeId, $lane) {
                $this->applyLane($task, $lane);
                app(TaskPlacement::class)->place($task, $status, $beforeId);
            });
        } catch (ValidationException $refused) {
            return $this->refuse($refused->validator->errors()->first());
        }

        broadcast(new TaskBoardUpdated($this->taskList))->toOthers();

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
