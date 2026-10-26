<div>
    @if($this->pendingProposals->isEmpty())
        <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
            <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">task_alt</span>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No tasks awaiting escalation review.</p>
        </div>
    @endif

    @foreach($this->pendingProposals as $proposal)
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5 mb-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between mb-2">
                <div>
                    <p class="text-sm font-medium text-gray-800 dark:text-white">{{ $proposal->task->title }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        {{ $proposal->task->taskList->name }} &middot; {{ $proposal->reason }}
                    </p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400">
                    Escalation candidate
                </span>
            </div>

            @if($rejectingProposalId === $proposal->id)
                <div class="flex items-center gap-2 mt-3">
                    <input aria-label="Reason for keeping as-is" type="text" wire:model="rejectReason" placeholder="Reason for keeping as-is"
                        class="flex-1 border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                    <button wire:click="confirmReject({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Confirm Reject
                    </button>
                    <button wire:click="cancelReject" class="text-xs px-3 py-1.5 rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-medium">
                        Cancel
                    </button>
                </div>
            @else
                <div class="flex items-center gap-2 mt-3">
                    <button wire:click="approve({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-green-600 hover:bg-green-700 text-white font-medium">
                        Approve Escalation
                    </button>
                    <button wire:click="promptReject({{ $proposal->id }})" class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-medium">
                        Reject
                    </button>
                </div>
            @endif
        </div>
    @endforeach
</div>
