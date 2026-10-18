<div>
    @if($this->boards->isNotEmpty())
        <div style="margin:2.5rem 0 1rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#a1a1aa;margin:0;">Archived boards</h2>
            <p style="font-size:0.75rem;color:#52525b;margin:0.2rem 0 0;">Hidden from everyone. Their tasks are kept and stay out of Today, reminders and escalation until you restore the board.</p>
        </div>
        <div class="dot-card" style="overflow:hidden;">
            @foreach($this->boards as $board)
                <div wire:key="archived-board-{{ $board->id }}" style="display:flex;align-items:center;gap:1rem;padding:0.75rem 1.25rem;{{ ! $loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
                    <span style="flex:1;min-width:0;font-size:0.9rem;font-weight:600;color:#d4d4d8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $board->name }}</span>
                    <span class="metric-val" style="font-size:0.75rem;color:#71717a;">{{ $board->tasks_count }} {{ $board->tasks_count === 1 ? 'task' : 'tasks' }}</span>
                    <span style="font-size:0.75rem;color:#52525b;">archived {{ $board->archived_at->diffForHumans() }}</span>
                    <button type="button" wire:click="restoreBoard({{ $board->id }})" class="dot-btn dot-btn-ghost" style="padding:4px 10px;">Restore</button>
                </div>
            @endforeach
        </div>
    @endif
</div>
