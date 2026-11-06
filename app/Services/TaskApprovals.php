<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\User;
use App\Notifications\TaskEventNotification;
use Illuminate\Validation\ValidationException;

/** Asking someone to sign a task off. A task with an open request cannot finish. Callers authorize first. */
class TaskApprovals
{
    public function __construct(private readonly TaskHistory $history) {}

    /** @throws ValidationException (key 'approval') */
    public function request(Task $task, User $approver, User $by): TaskApproval
    {
        if (! $approver->belongsToTeam($task->taskList->team)) {
            throw ValidationException::withMessages(['approval' => 'That person is not on this team.']);
        }

        if ($approver->id === $by->id) {
            throw ValidationException::withMessages(['approval' => 'Someone else has to approve this.']);
        }

        if ($task->approvals()->where('status', 'pending')->where('approver_id', $approver->id)->exists()) {
            throw ValidationException::withMessages(['approval' => $approver->name.' has already been asked.']);
        }

        $approval = $task->approvals()->create(['approver_id' => $approver->id, 'requested_by' => $by->id]);

        $this->history->record($task, 'approval', 'Asked '.$approver->name.' to approve it');
        $approver->notify(new TaskEventNotification($task, 'approval', 'Approval needed', "{$by->name} asked you to approve \"{$task->title}\"."));

        return $approval;
    }

    /** Only the person asked can answer. */
    public function decide(TaskApproval $approval, User $by, bool $approved, ?string $note = null): void
    {
        abort_unless($approval->approver_id === $by->id && $approval->status === 'pending', 403);

        $approval->update(['status' => $approved ? 'approved' : 'declined', 'note' => $note ?: null, 'decided_at' => now()]);

        $task = $approval->task;
        $this->history->record($task, 'approval', ($approved ? 'Approved it' : 'Declined it').($note ? ': '.$note : ''));

        $approval->requester?->notify(new TaskEventNotification($task, 'approval', $approved ? 'Approved' : 'Declined', "{$by->name} ".($approved ? 'approved' : 'declined')." \"{$task->title}\"."));

        if ($approved) {
            app(Automations::class)->fire('approved', $task, "approved:{$approval->id}");
        }
    }
}
