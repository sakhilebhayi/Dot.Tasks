<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskList;
use App\Models\User;
use App\Services\AiTaskBreakdownService;
use App\Services\CommentMentions;
use App\Services\TaskArchiveService;
use App\Services\TaskCommentService;
use App\Services\TaskDependencyService;
use App\Services\TaskEditor;
use App\Services\TaskLabelService;
use App\Services\TaskPlacement;
use App\Services\TaskStatusService;
use App\Services\TaskSteps;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class TaskDetail extends Component
{
    /** Everything the drawer shows, loaded together. */
    private const RELATIONS = ['taskList.columns', 'assignee', 'collaborators', 'checklistItems', 'activities.user', 'labels', 'subtasks', 'comments.user', 'taskList.team', 'blockedBy', 'blocking'];

    public ?Task $task = null;

    #[Validate('required|string|max:5000')]
    public string $commentBody = '';

    public string $newSubtask = '';

    public string $newChecklistItem = '';

    public string $completionNote = '';

    public bool $breaking = false;

    /** Bumped on every status change attempt so a refused one resets the select to the real status. */
    public int $statusAttempts = 0;

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

    /** Mentions in the thread are matched against this task's team only. */
    #[Computed]
    public function mentions(): CommentMentions
    {
        return CommentMentions::forTeam($this->task->taskList->team);
    }

    /** Blockers that are not done yet, for the explanation next to the status. */
    #[Computed]
    public function openBlockers()
    {
        return $this->task ? app(TaskDependencyService::class)->openBlockers($this->task) : collect();
    }

    /**
     * Other open tasks on this board that could block this one: not itself,
     * not done, not already a blocker. Same board only, same as the server rule.
     */
    #[Computed]
    public function blockerCandidates()
    {
        if (! $this->task) {
            return collect();
        }

        return Task::where('task_list_id', $this->task->task_list_id)
            ->whereKeyNot($this->task->id)
            ->where('status', '!=', 'done')
            ->whereNotIn('id', $this->task->blockedBy->pluck('id'))
            ->orderBy('title')
            ->limit(200)
            ->get(['id', 'title']);
    }

    public function addBlocker(int $blockerId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        app(TaskDependencyService::class)->add($this->task, $blockerId);

        $this->refreshTask();
    }

    public function removeBlocker(int $blockerId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        app(TaskDependencyService::class)->remove($this->task, $blockerId);

        $this->refreshTask();
    }

    private function refreshTask(): void
    {
        $this->task = $this->task->fresh(self::RELATIONS);
        $this->dispatch('task-updated');
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

        $this->statusAttempts++;
        $this->resetErrorBag('status');

        $columns = $this->task->taskList->columns;
        $column = ctype_digit($status) ? $columns->firstWhere('id', (int) $status) : $columns->firstWhere('category', $status);
        if (! $column) {
            throw ValidationException::withMessages(['status' => 'That column is not on this board.']);
        }

        app(TaskStatusService::class)->move($this->task, $column);

        broadcast(new TaskBoardUpdated($this->task->taskList))->toOthers();
        $this->task = $this->task->fresh(self::RELATIONS);
        $this->dispatch('task-updated');
    }

    /** One field of the task, saved as it is left. */
    public function saveField(string $field, mixed $value): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        app(TaskEditor::class)->set($this->task, $field, $value);

        $this->refreshTask();
    }

    public function toggleCollaborator(int $userId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('assign', $this->task);

        $ids = $this->task->collaborators->pluck('id');
        $next = $ids->contains($userId) ? $ids->reject(fn (int $id) => $id === $userId) : $ids->push($userId);
        app(TaskEditor::class)->setCollaborators($this->task, $next->all());

        $this->refreshTask();
    }

    public function addSubtask(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $this->validate(['newSubtask' => 'required|string|max:255']);
        app(TaskSteps::class)->addSubtask($this->task, $this->newSubtask);
        $this->newSubtask = '';

        $this->refreshTask();
    }

    /** Tick a subtask off, or back on. Blockers are checked the same way as anywhere else. */
    public function toggleSubtask(int $subtaskId): void
    {
        $sub = $this->task?->subtasks()->find($subtaskId) ?? abort(404);
        $this->authorize('move', $sub);

        app(TaskStatusService::class)->move($sub, $sub->status === 'done' ? 'todo' : 'done');

        $this->refreshTask();
    }

    /** @return Collection<int, TaskList> the other places this task can go */
    #[Computed]
    public function otherBoards()
    {
        $task = $this->task;
        if (! $task || $task->parent_id) {
            return collect();
        }

        return $task->taskList->team->taskLists()->whereKeyNot($task->task_list_id)->orderByDesc('is_inbox')->orderBy('name')->get()
            ->filter(fn (TaskList $board) => auth()->user()->can('create', [Task::class, $board]))->values();
    }

    public function moveToBoard(int $boardId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('move', $this->task);

        $board = $this->otherBoards->firstWhere('id', $boardId) ?? abort(403);
        app(TaskPlacement::class)->moveToBoard($this->task, $board);

        unset($this->otherBoards);
        $this->refreshTask();
    }

    /** Reorder the subtasks, by hand or by keyboard. */
    public function placeSubtask(int $subtaskId, ?int $beforeId = null): void
    {
        $sub = $this->task?->subtasks()->find($subtaskId) ?? abort(404);
        $this->authorize('update', $this->task);

        app(TaskPlacement::class)->placeSubtask($sub, $beforeId);

        $this->refreshTask();
    }

    public function addChecklistItem(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $this->validate(['newChecklistItem' => 'required|string|max:255']);
        app(TaskSteps::class)->addChecklistItem($this->task, $this->newChecklistItem);
        $this->newChecklistItem = '';

        $this->refreshTask();
    }

    public function toggleChecklistItem(int $itemId): void
    {
        $item = $this->ownChecklistItem($itemId);
        $this->authorize('update', $this->task);

        app(TaskSteps::class)->toggleChecklistItem($item);

        $this->refreshTask();
    }

    public function removeChecklistItem(int $itemId): void
    {
        $item = $this->ownChecklistItem($itemId);
        $this->authorize('update', $this->task);

        $item->delete();

        $this->refreshTask();
    }

    /** A checklist line that needs more than a tick becomes a subtask. */
    public function promoteChecklistItem(int $itemId): void
    {
        $item = $this->ownChecklistItem($itemId);
        $this->authorize('update', $this->task);

        app(TaskSteps::class)->promote($item);

        $this->refreshTask();
    }

    private function ownChecklistItem(int $itemId): TaskChecklistItem
    {
        return $this->task?->checklistItems()->find($itemId) ?? abort(404);
    }

    /** A new task beside this one, and the panel moves to it. */
    public function duplicate(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('create', [Task::class, $this->task->taskList]);

        $copy = app(TaskSteps::class)->duplicate($this->task);

        $this->openTask($copy->id);
        $this->dispatch('task-updated');
    }

    /** Finish the task, with a note if there is something to say. */
    public function finish(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('move', $this->task);
        $this->validate(['completionNote' => 'nullable|string|max:2000']);
        $this->resetErrorBag('status');

        app(TaskStatusService::class)->move($this->task, 'done');
        if (trim($this->completionNote) !== '') {
            $this->task->update(['completion_note' => trim($this->completionNote)]);
        }
        $this->completionNote = '';

        broadcast(new TaskBoardUpdated($this->task->taskList))->toOthers();
        $this->refreshTask();
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

        app(TaskEditor::class)->assign($this->task, $assignee, auth()->user());

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

        app(TaskCommentService::class)->add($this->task, auth()->user(), $this->commentBody);

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
                'creator_id' => auth()->id(),
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
