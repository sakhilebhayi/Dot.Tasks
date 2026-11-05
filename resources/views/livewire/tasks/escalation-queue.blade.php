<div>
    @if($this->pendingProposals->isEmpty())
        <div class="empty">No tasks awaiting escalation review.</div>
    @endif

    @if($this->pendingProposals->isNotEmpty())
        <div class="rows">
            @foreach($this->pendingProposals as $proposal)
                <div class="row" style="flex-wrap:wrap" wire:key="proposal-{{ $proposal->id }}">
                    <div class="row-main">
                        <b>{{ $proposal->task->title }}</b>
                        <span>{{ $proposal->task->taskList->name }} &middot; {{ $proposal->reason }}</span>
                    </div>
                    <span class="chip wait">Escalation candidate</span>
                    @if($rejectingProposalId === $proposal->id)
                        <div class="page-actions" style="width:100%">
                            <input class="input" style="flex:1 1 240px" aria-label="Reason for keeping as-is" type="text" wire:model="rejectReason" placeholder="Reason for keeping as-is">
                            <button class="btn" wire:click="confirmReject({{ $proposal->id }})">Confirm Reject</button>
                            <button class="btn quiet" wire:click="cancelReject">Cancel</button>
                        </div>
                    @else
                        <div class="page-actions">
                            <button class="btn small primary" wire:click="approve({{ $proposal->id }})">Approve Escalation</button>
                            <button class="btn small" wire:click="promptReject({{ $proposal->id }})">Reject</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
