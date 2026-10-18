<?php

namespace App\Livewire\Tasks;

use App\Events\TaskEscalated;
use App\Models\TaskEscalationProposal;
use App\Services\Notify\NotifyClient;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EscalationQueue extends Component
{
    public ?int $rejectingProposalId = null;

    public string $rejectReason = '';

    #[Computed]
    public function pendingProposals(): Collection
    {
        $team = auth()->user()->currentTeam;

        if (! $team) {
            return collect();
        }

        // Scoped to the current team (tenant boundary) AND to lists the user
        // owns (only the list owner may act on a proposal, see
        // TaskPolicy::escalate()).
        return TaskEscalationProposal::where('status', 'pending')
            ->whereHas('task', fn ($task) => $task->forTeam($team)
                ->whereHas('taskList', fn ($list) => $list->where('owner_id', auth()->id())))
            ->with('task.taskList')
            ->latest()
            ->get();
    }

    public function approve(int $proposalId): void
    {
        $proposal = TaskEscalationProposal::find($proposalId);

        // Also gone if its task has since been archived, deleted or moved onto an archived board.
        if (! $proposal || ! $proposal->task) {
            return;
        }

        $this->authorize('escalate', $proposal->task);

        if ($proposal->status !== 'pending') {
            return;
        }

        $proposal->task->update(['escalated_at' => now()]);
        TaskEscalated::dispatch($proposal->task);

        $proposal->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        // There is no in-app notification for an escalation. The email goes to the person whose task it is,
        // unless they are the one approving it.
        $assignee = $proposal->task->assignee;
        if ($assignee && $assignee->id !== auth()->id()) {
            app(NotifyClient::class)->taskEscalated($proposal->task, $proposal, $assignee);
        }

        unset($this->pendingProposals);
    }

    public function promptReject(int $proposalId): void
    {
        $proposal = TaskEscalationProposal::find($proposalId);

        // Also gone if its task has since been archived, deleted or moved onto an archived board.
        if (! $proposal || ! $proposal->task) {
            return;
        }

        $this->authorize('escalate', $proposal->task);

        $this->rejectingProposalId = $proposalId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingProposalId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(int $proposalId): void
    {
        $proposal = TaskEscalationProposal::find($proposalId);

        // Also gone if its task has since been archived, deleted or moved onto an archived board.
        if (! $proposal || ! $proposal->task) {
            return;
        }

        $this->authorize('escalate', $proposal->task);

        if ($proposal->status !== 'pending') {
            return;
        }

        if (trim($this->rejectReason) === '') {
            return;
        }

        $proposal->update([
            'status' => 'rejected',
            'rejected_reason' => $this->rejectReason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->rejectingProposalId = null;
        $this->rejectReason = '';
        unset($this->pendingProposals);
    }

    public function render(): View
    {
        return view('livewire.tasks.escalation-queue');
    }
}
