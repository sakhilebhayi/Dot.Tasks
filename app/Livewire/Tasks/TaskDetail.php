<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\NewCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Services\AiTaskBreakdownService;
use App\Services\TaskArchiveService;
use App\Services\TaskLabelService;
use App\Services\TaskStatusService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TaskDetail extends Component
{
    /** Everything the drawer shows, loaded together. */
    private const RELATIONS = ['assignee', 'labels', 'subtasks', 'comments.user', 'taskList.team'];

    public ?Task $task = null;

    #[Validate('required|string')]
    public string $commentBody = '';

    public bool $breaking = false;

    /** The destructive action awaiting confirmation in the drawer: 'archive', 'delete' or null. */
    public ?string $confirming = null;

    public ?string $aiError = null;

    /**
     * Loads a task by ID dispatched from the client via the `open-task`
     * Livewire event (e.g. clicking a card on the board). Without the
     * authorize() call here, any authenticated user could pass an arbitrary
     * task ID — including one belonging to another team — and view, comment
     * on, or AI-breakdown a task they have no relationship to.
     */
    #[On('open-task')]
    public function openTask(int $taskId): void
    {
        $task = Task::with(self::RELATIONS)->find($taskId) ?? abort(404);
        $this->authorize('view', $task);

        $this->task = $task;
        $this->confirming = null;
        $this->aiError = null;
        $this->breaking = false;
        $this->commentBody = '';
    }

    public function close(): void
    {
        $this->task = null;
        $this->confirming = null;
    }

    /** Subtasks that archiving or deleting this task would take along, for the confirmation text. */
    #[Computed]
    public function affectedSubtasks(): int
    {
        return $this->task
            ? app(TaskArchiveService::class)->subtaskCount($this->task, includeArchived: $this->confirming === 'delete')
            : 0;
    }

    public function confirmArchive(): void
    {
        $this->authorize('archive', $this->task);
        $this->confirming = 'archive';
    }

    public function confirmDelete(): void
    {
        $this->authorize('delete', $this->task);
        $this->confirming = 'delete';
    }

    public function cancelConfirm(): void
    {
        $this->confirming = null;
        $this->resetErrorBag();
    }

    /** Archive the task; with subtasks it needs $confirmed, then takes them along. */
    public function archiveTask(bool $confirmed = false): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('archive', $this->task);

        app(TaskArchiveService::class)->archive($this->task, $confirmed);

        $this->afterRetire();
    }

    /** Soft delete the task (admins and the owner); with subtasks it needs $confirmed. */
    public function deleteTask(bool $confirmed = false): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('delete', $this->task);

        app(TaskArchiveService::class)->delete($this->task, $confirmed);

        $this->afterRetire();
    }

    private function afterRetire(): void
    {
        $taskList = $this->task->taskList;

        $this->task = null;
        $this->confirming = null;

        broadcast(new TaskBoardUpdated($taskList))->toOthers();
        $this->dispatch('task-updated');
    }

    /**
     * Users who can be assigned this task, scoped to the task list's team —
     * matches the server-side check enforced in assignTask().
     */
    #[Computed]
    public function assignableUsers()
    {
        if (! $this->task) {
            return collect();
        }

        return $this->task->taskList->team->allUsers();
    }

    /** The team's labels, for the picker. Same team as the task, so a label id from another team is never offered. */
    #[Computed]
    public function teamLabels()
    {
        if (! $this->task) {
            return collect();
        }

        return Label::where('team_id', $this->task->taskList->team_id)->orderBy('name')->get();
    }

    public function updateStatus(string $status): void
    {
        if (! $this->task) {
            return;
        }

        // Re-authorize against the task currently loaded on the server, not
        // whatever the client claims — Livewire re-hydrates $this->task from
        // the request payload by primary key, so this guards against a
        // tampered request pointing at another team's task.
        $this->authorize('move', $this->task);

        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(Task::STATUSES)],
        ])->validate();

        app(TaskStatusService::class)->move($this->task, $status);

        broadcast(new TaskBoardUpdated($this->task->taskList))->toOthers();
        $this->task = $this->task->fresh(self::RELATIONS);
        $this->dispatch('task-updated');
    }

    public function assignTask(?int $userId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('assign', $this->task);

        $assignee = null;

        if ($userId) {
            // Only allow assigning to someone who actually belongs to the
            // task list's team, regardless of what a tampered client sends.
            $assignee = User::find($userId);
            abort_unless($assignee && $assignee->belongsToTeam($this->task->taskList->team), 403);
        }

        $this->task->update(['assignee_id' => $assignee?->id]);

        if ($assignee && $assignee->id !== auth()->id()) {
            $assignee->notify(new TaskAssignedNotification($this->task));
        }

        $this->task = $this->task->fresh(self::RELATIONS);
    }

    public function toggleLabel(int $labelId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('label', $this->task);

        app(TaskLabelService::class)->toggle($this->task, $labelId);

        $this->task = $this->task->fresh(self::RELATIONS);
        $this->dispatch('task-updated');
    }

    public function addComment(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('comment', $this->task);

        $this->validateOnly('commentBody');

        TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => auth()->id(),
            'body' => $this->commentBody,
        ]);

        if ($this->task->assignee_id && $this->task->assignee_id !== auth()->id()) {
            $this->task->assignee->notify(new NewCommentNotification($this->task));
        }

        $this->commentBody = '';
        $this->task = $this->task->fresh(self::RELATIONS);
    }

    public function aiBreakdown(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $this->breaking = true;
        $this->aiError = null;

        $result = app(AiTaskBreakdownService::class)->breakdown($this->task, auth()->id());

        if ($result['source'] !== AiTaskBreakdownService::SOURCE_AI) {
            // Never fall back to canned subtasks: say plainly that the AI is
            // unavailable and create nothing.
            $this->aiError = match ($result['reason'] ?? null) {
                'not_configured' => 'AI breakdown unavailable: no API key is configured.',
                default => 'AI breakdown unavailable: the AI service did not return a usable answer. Please try again later.',
            };
            $this->breaking = false;

            return;
        }

        foreach ($result['subtasks'] ?? [] as $i => $sub) {
            Task::create([
                'task_list_id' => $this->task->task_list_id,
                'parent_id' => $this->task->id,
                'title' => $sub['title'],
                'priority' => $sub['priority'] ?? 'medium',
                'estimated_minutes' => $sub['estimated_minutes'] ?? null,
                'status' => 'todo',
                'sort_order' => $i,
            ]);
        }

        $this->breaking = false;
        $this->task = $this->task->fresh(self::RELATIONS);
    }

    public function render(): View
    {
        return view('livewire.tasks.task-detail');
    }
}
