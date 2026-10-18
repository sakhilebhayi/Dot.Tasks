<div>
    @if($notice)
        <div role="status" class="dot-card" style="padding:0.75rem 1.25rem;margin-bottom:1rem;border-color:rgba(34,197,94,0.35);font-size:0.85rem;color:#86efac;">{{ $notice }}</div>
    @endif
    @error('restore')
        <div role="alert" class="dot-card" style="padding:0.75rem 1.25rem;margin-bottom:1rem;border-color:rgba(239,68,68,0.35);font-size:0.85rem;color:#fca5a5;">{{ $message }}</div>
    @enderror

    <div class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div style="display:flex;align-items:flex-end;gap:0.75rem;flex-wrap:wrap;">
            <div style="min-width:9rem;">
                <label for="removed-type" style="display:block;font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:0.4rem;">Type</label>
                <select id="removed-type" wire:model.live="type" class="dot-input">
                    <option value="">Tasks and subtasks</option>
                    <option value="task">Tasks</option>
                    <option value="subtask">Subtasks</option>
                </select>
            </div>
            <div style="min-width:11rem;">
                <label for="removed-board" style="display:block;font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:0.4rem;">Board</label>
                <select id="removed-board" wire:model.live="board" class="dot-input">
                    <option value="">Every board</option>
                    @foreach($this->boards as $each)
                        <option value="{{ $each->id }}">{{ $each->name }}{{ $each->archived_at ? ' (archived)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1;min-width:14rem;">
                <label for="removed-search" style="display:block;font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:0.4rem;">Search</label>
                <input id="removed-search" wire:model.live.debounce.300ms="search" type="search" maxlength="100" placeholder="Title or description" class="dot-input">
            </div>
            @if($type !== '' || $board !== '' || trim($search) !== '')
                <button type="button" wire:click="$set('type', ''); $set('board', ''); $set('search', '')" class="dot-btn dot-btn-ghost">Clear</button>
            @endif
        </div>
    </div>

    @if($this->removedTasks->isEmpty())
        <div class="dot-card" style="padding:3rem 2rem;text-align:center;">
            @if($type !== '' || $board !== '' || trim($search) !== '')
                <p style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;">No removed tasks match these filters</p>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Clear the filters to see everything that was removed.</p>
            @else
                <p style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;">Nothing has been removed</p>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">When someone deletes a task it lands here, and an admin or the owner can restore it. Archived tasks and boards are not removed: restore those from their board's Archived list or the dashboard.</p>
            @endif
        </div>
    @else
        <div class="dot-card" style="overflow:hidden;">
            @foreach($this->removedTasks as $item)
                <div wire:key="removed-{{ $item->task->id }}" style="display:flex;align-items:center;gap:1rem;padding:0.8rem 1.25rem;flex-wrap:wrap;{{ ! $loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
                    <div style="flex:1;min-width:14rem;">
                        <div style="font-size:0.9rem;font-weight:600;color:#f4f4f5;overflow:hidden;text-overflow:ellipsis;">{{ $item->task->title }}</div>
                        <div style="font-size:0.75rem;color:#71717a;">
                            {{ $item->isSubtask() ? 'Subtask' : 'Task' }}
                            @if($item->isSubtask() && $item->parent) of {{ $item->parent->title }}@if($item->parent->trashed()) (removed)@endif @endif
                            &middot; {{ $item->board?->name ?? 'Board gone' }}@if($item->board?->archived_at) (archived)@endif
                            @if($item->task->archived_at) &middot; archived before it was deleted @endif
                        </div>
                    </div>
                    <span style="font-size:0.75rem;color:#71717a;white-space:nowrap;min-width:8rem;text-align:right;" title="{{ $item->task->deleted_at->format('M j, Y H:i') }} UTC">deleted {{ $item->task->deleted_at->diffForHumans() }}</span>
                    <div style="min-width:15rem;display:flex;justify-content:flex-end;">
                    @can('restoreDeleted', $item->task)
                        @if($item->blockedBy)
                            <span style="font-size:0.75rem;color:#a1a1aa;max-width:16rem;text-align:right;">
                                @switch($item->blockedBy['kind'])
                                    @case('parent_removed')
                                        Restore the parent task
                                        <a href="{{ route('removed.index', ['q' => $item->blockedBy['name']]) }}" style="color:var(--accent);">{{ $item->blockedBy['name'] }}</a> first
                                        @break
                                    @case('parent_archived')
                                        Its parent task {{ $item->blockedBy['name'] }} is archived: restore it from its board's Archived list first
                                        @break
                                    @case('board_archived')
                                        Restore the board
                                        <a href="{{ route('dashboard') }}" style="color:var(--accent);">{{ $item->blockedBy['name'] }}</a> first
                                        @break
                                    @default
                                        Its board no longer exists
                                @endswitch
                            </span>
                        @else
                            <button type="button" wire:click="restore({{ $item->task->id }})" wire:loading.attr="disabled" class="dot-btn dot-btn-ghost" style="padding:4px 10px;">Restore</button>
                        @endif
                    @endcan
                    </div>
                </div>
            @endforeach
        </div>
        <div style="margin-top:1rem;">
            {{ $this->removedTasks->links() }}
        </div>
    @endif
</div>
