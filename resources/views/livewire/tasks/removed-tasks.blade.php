<div>
    @if($notice)
        <div role="status" class="panel" style="margin-bottom:12px">{{ $notice }}</div>
    @endif
    @error('restore')
        <div role="alert" class="alert">{{ $message }}</div>
    @enderror

    <div class="filters">
        <label class="sr" for="removed-type">Type</label>
        <select class="select bare" id="removed-type" wire:model.live="type">
            <option value="">Tasks and subtasks</option>
            <option value="task">Tasks</option>
            <option value="subtask">Subtasks</option>
        </select>
        <label class="sr" for="removed-board">Board</label>
        <select class="select bare" id="removed-board" wire:model.live="board">
            <option value="">Every board</option>
            @foreach($this->boards as $each)
                <option value="{{ $each->id }}">{{ $each->name }}{{ $each->archived_at ? ' (archived)' : '' }}</option>
            @endforeach
        </select>
        <label class="sr" for="removed-search">Search</label>
        <x-dot.filter-search name="search" id="removed-search" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Title or description" label="Search" />
        @if($type !== '' || $board !== '' || trim($search) !== '')
            <button type="button" class="link" wire:click="$set('type', ''); $set('board', ''); $set('search', '')">Clear</button>
        @endif
    </div>

    @if($this->removedTasks->isEmpty())
        <div class="empty">
            @if($type !== '' || $board !== '' || trim($search) !== '')
                <p>No removed tasks match these filters</p>
                <p>Clear the filters to see everything that was removed.</p>
            @else
                <p>Nothing has been removed</p>
                <p>When someone deletes a task it lands here, and an admin or the owner can restore it. Archived tasks and boards are not removed: restore those from their board's Archived list or the dashboard.</p>
            @endif
        </div>
    @else
        <div class="rows">
            @foreach($this->removedTasks as $item)
                <div class="row" style="flex-wrap:wrap" wire:key="removed-{{ $item->task->id }}">
                    <div class="row-main">
                        <b>{{ $item->task->title }}</b>
                        <span>
                            {{ $item->isSubtask() ? 'Subtask' : 'Task' }}
                            @if($item->isSubtask() && $item->parent) of {{ $item->parent->title }}@if($item->parent->trashed()) (removed)@endif @endif
                            &middot; {{ $item->board?->name ?? 'Board gone' }}@if($item->board?->archived_at) (archived)@endif
                            @if($item->task->archived_at) &middot; archived before it was deleted @endif
                        </span>
                    </div>
                    <span style="color:var(--muted);font-size:12px" title="{{ $item->task->deleted_at->format('M j, Y H:i') }} UTC">deleted {{ $item->task->deleted_at->diffForHumans() }}</span>
                    <div>
                    @can('restoreDeleted', $item->task)
                        @if($item->blockedBy)
                            <span>
                                @switch($item->blockedBy['kind'])
                                    @case('parent_removed')
                                        Restore the parent task
                                        <a class="link" href="{{ route('removed.index', ['q' => $item->blockedBy['name']]) }}">{{ $item->blockedBy['name'] }}</a> first
                                        @break
                                    @case('parent_archived')
                                        Its parent task {{ $item->blockedBy['name'] }} is archived: restore it from its board's Archived list first
                                        @break
                                    @case('board_archived')
                                        Restore the board
                                        <a class="link" href="{{ route('dashboard') }}">{{ $item->blockedBy['name'] }}</a> first
                                        @break
                                    @default
                                        Its board no longer exists
                                @endswitch
                            </span>
                        @else
                            <button type="button" class="btn small" wire:click="restore({{ $item->task->id }})" wire:loading.attr="disabled">Restore</button>
                        @endif
                    @endcan
                    </div>
                </div>
            @endforeach
        </div>
        <div>
            {{ $this->removedTasks->links() }}
        </div>
    @endif
</div>
