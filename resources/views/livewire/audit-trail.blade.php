<div>
    @if($events->isEmpty())
        <div class="empty">
            <p>Nothing recorded yet.</p>
            <p>Changes to boards, rules, members and exports appear here.</p>
        </div>
    @else
        <div class="rows">
            @foreach($events as $event)
                <div class="row" wire:key="event-{{ $event->id }}">
                    <div class="row-main">
                        <b style="white-space:normal">{{ $event->what }}</b>
                        <span>{{ $event->user?->name ?? 'Scheduled job' }}</span>
                    </div>
                    <time datetime="{{ $event->created_at->toIso8601String() }}" style="color:var(--muted);font-size:12px;white-space:nowrap">{{ $event->created_at->timezone(auth()->user()->currentTeam->timezone ?? config('app.timezone'))->format('j M Y, H:i') }}</time>
                </div>
            @endforeach
        </div>
        <div style="margin-top:12px">{{ $events->links() }}</div>
    @endif
</div>
