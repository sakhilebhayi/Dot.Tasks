<div>
    @if($this->boards->isNotEmpty())
        <h2 class="section-title">Archived boards</h2>
        <p style="margin-bottom:8px;color:var(--muted)">Hidden from everyone. Their tasks are kept and stay out of Today, reminders and escalation until you restore the board.</p>
        <div class="rows">
            @foreach($this->boards as $board)
                <div class="row" wire:key="archived-board-{{ $board->id }}">
                    <div class="row-main">
                        <b>{{ $board->name }}</b>
                        <span>{{ $board->tasks_count }} {{ $board->tasks_count === 1 ? 'task' : 'tasks' }} &middot; archived {{ $board->archived_at->diffForHumans() }}</span>
                    </div>
                    <button type="button" class="btn small" wire:click="restoreBoard({{ $board->id }})">Restore</button>
                </div>
            @endforeach
        </div>
    @endif
</div>
