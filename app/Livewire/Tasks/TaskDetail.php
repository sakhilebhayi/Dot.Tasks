<?php

namespace App\Livewire\Tasks;

use App\Events\TaskBoardUpdated;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskField;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\AiTaskBreakdownService;
use App\Services\CommentMentions;
use App\Services\TaskApprovals;
use App\Services\TaskArchiveService;
use App\Services\TaskAttachments;
use App\Services\TaskClarity;
use App\Services\TaskCommentService;
use App\Services\TaskDependencyService;
use App\Services\TaskEditor;
use App\Services\TaskFieldValues;
use App\Services\TaskLabelService;
use App\Services\TaskPlacement;
use App\Services\TaskStatusService;
use App\Services\TaskSteps;
use App\Services\TaskSummaries;
use App\Services\TaskTemplates;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class TaskDetail extends Component
{
    use WithFileUploads;

    /** Everything the drawer shows, loaded together. */
    private const RELATIONS = ['taskList.columns', 'assignee', 'collaborators', 'approvals.approver', 'watchers', 'attachments.user', 'fieldValues', 'checklistItems', 'activities.user', 'labels', 'subtasks', 'comments.user', 'taskList.team', 'blockedBy', 'blocking'];

    public ?Task $task = null;

    #[Validate('required|string|max:5000')]
    public string $commentBody = '';

    /** Subtasks the AI suggests, waiting for a person to choose. @var list<array{title: string, priority: string, estimated_minutes: int|null}> */
    #[Locked]
    public array $suggested = [];

    /** @var list<array{text: string, sources: list<string>}> */
    #[Locked]
    public array $summary = [];

    public ?int $replyingTo = null;

    public bool $commentInternal = false;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $blockerType = 'ff';

    public bool $templateSaved = false;

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
        $this->templateSaved = false;
        $this->suggested = [];
        $this->summary = [];
        $this->replyingTo = null;
        $this->commentInternal = false;
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

        app(TaskDependencyService::class)->add($this->task, $blockerId, $this->blockerType);

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

    /** What this task still leaves open, from what is on it. @return list<string> */
    #[Computed]
    public function gaps(): array
    {
        return $this->task ? app(TaskClarity::class)->gaps($this->task) : [];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TaskField> */
    #[Computed]
    public function customFields()
    {
        return TaskField::where('team_id', $this->task?->taskList->team_id)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function saveCustomField(int $fieldId, ?string $value): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $field = $this->customFields->firstWhere('id', $fieldId) ?? abort(404);
        app(TaskFieldValues::class)->set($this->task, $field, $value);

        $this->refreshTask();
    }

    public function askForApproval(int $userId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $person = $this->assignableUsers->firstWhere('id', $userId) ?? abort(403);
        app(TaskApprovals::class)->request($this->task, $person, auth()->user());

        $this->refreshTask();
    }

    public function answerApproval(int $approvalId, bool $approved, ?string $note = null): void
    {
        $approval = $this->task?->approvals()->find($approvalId) ?? abort(404);

        app(TaskApprovals::class)->decide($approval, auth()->user(), $approved, $note ? mb_substr(trim($note), 0, 500) : null);

        $this->refreshTask();
    }

    /** Keep this task's shape for next time. */
    public function saveAsTemplate(string $name): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $name = trim($name);
        $taken = TaskTemplate::where('team_id', $this->task->taskList->team_id)->where('name', $name)->exists();
        if ($name === '' || mb_strlen($name) > 120 || $taken) {
            throw ValidationException::withMessages(['template' => $taken ? 'There is already a template with that name.' : 'Give the template a name.']);
        }

        app(TaskTemplates::class)->fromTask($this->task, $name, auth()->user());

        $this->templateSaved = true;
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

        $replyTo = $this->replyingTo ? ($this->task->comments()->find($this->replyingTo) ?? abort(404)) : null;
        app(TaskCommentService::class)->add($this->task, auth()->user(), $this->commentBody, $replyTo, $this->commentInternal);

        $this->commentBody = '';
        $this->replyingTo = null;
        $this->commentInternal = false;
        $this->task = $this->task->fresh(self::RELATIONS);
    }

    public function replyTo(?int $commentId): void
    {
        if ($commentId !== null && ! $this->task?->comments()->whereKey($commentId)->exists()) {
            abort(404);
        }

        $this->replyingTo = $commentId;
    }

    /** People take back their own words. Admins and the owner can tidy any. */
    public function deleteComment(int $commentId): void
    {
        $comment = $this->task?->comments()->find($commentId) ?? abort(404);
        abort_unless($comment->user_id === auth()->id() || auth()->user()->can('delete', $this->task), 403);

        $comment->replies()->delete();
        $comment->delete();

        $this->task = $this->task->fresh(self::RELATIONS);
    }

    /** The discussion this person can read: top-level comments with their replies, internal notes left out for viewers. */
    #[Computed]
    public function discussion()
    {
        $seesNotes = auth()->user()->can('comment', $this->task);
        $comments = $this->task->comments->filter(fn (TaskComment $comment) => $seesNotes || ! $comment->internal);

        return $comments->whereNull('parent_id')->values()->map(fn (TaskComment $comment) => [
            'comment' => $comment,
            'replies' => $comments->where('parent_id', $comment->id)->values(),
        ]);
    }

    public function toggleWatching(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('view', $this->task);

        $this->task->watchers()->toggle(auth()->id());

        $this->refreshTask();
    }

    /** Give the task to someone with a note, so what they need is in one place. */
    public function handOver(int $userId, string $note): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('assign', $this->task);

        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['handover' => 'Say what they need to know.']);
        }

        $person = $this->assignableUsers->firstWhere('id', $userId) ?? abort(403);

        app(TaskEditor::class)->assign($this->task, $person, auth()->user());
        app(TaskCommentService::class)->add($this->task, auth()->user(), 'Handover to '.$person->name.': '.$note);

        $this->refreshTask();
    }

    public function updatedUpload(): void
    {
        $this->authorize('update', $this->task);

        $this->validateOnly('upload', ['upload' => ['required', 'file', 'max:'.TaskAttachments::MAX_KB, 'mimes:'.TaskAttachments::TYPES]]);

        app(TaskAttachments::class)->store($this->task, $this->upload, auth()->user());

        $this->reset('upload');
        $this->refreshTask();
    }

    public function removeFile(int $attachmentId): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        app(TaskAttachments::class)->remove($this->task->attachments()->find($attachmentId) ?? abort(404));

        $this->refreshTask();
    }

    /** Ask for suggested subtasks. Nothing is added until a person picks from them. */
    public function aiBreakdown(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        $this->aiError = null;
        $this->suggested = [];

        $result = app(AiTaskBreakdownService::class)->breakdown($this->task, auth()->id());

        if ($result['source'] !== AiTaskBreakdownService::SOURCE_AI) {
            // Never fall back to canned subtasks: say plainly that the AI is
            // unavailable and create nothing.
            $this->aiError = 'AI breakdown unavailable: '.$this->aiReason($result['reason'] ?? null);

            return;
        }

        $this->suggested = $result['subtasks'];
    }

    /** @param  list<int|string>  $picked  positions in the suggestions */
    public function acceptSuggested(array $picked): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('update', $this->task);

        foreach (array_map('intval', $picked) as $index) {
            if (isset($this->suggested[$index])) {
                $sub = app(TaskSteps::class)->addSubtask($this->task, $this->suggested[$index]['title']);
                $sub->update(['priority' => $this->suggested[$index]['priority'], 'estimated_minutes' => $this->suggested[$index]['estimated_minutes']]);
            }
        }

        $this->suggested = [];
        $this->refreshTask();
    }

    public function dismissSuggested(): void
    {
        $this->suggested = [];
    }

    /** A summary with each point tied to the comments it came from. Shown, never saved. */
    public function aiSummary(): void
    {
        if (! $this->task) {
            return;
        }

        $this->authorize('view', $this->task);

        $this->aiError = null;
        $result = app(TaskSummaries::class)->summarise($this->task, auth()->id(), $this->discussion->flatMap(fn (array $t) => [$t['comment'], ...$t['replies']]));

        $result['ok'] ? $this->summary = $result['points'] : $this->aiError = 'AI summary unavailable: '.$this->aiReason($result['reason'] ?? null);
    }

    private function aiReason(?string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'AI has not been set up for this workspace.',
            'over_cap' => "this month's AI allowance is used up.",
            'refused' => 'the AI service declined this request.',
            default => 'the AI service did not return a usable answer. Please try again later.',
        };
    }

    public function render(): View
    {
        return view('livewire.tasks.task-detail');
    }
}
