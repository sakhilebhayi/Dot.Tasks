<div x-data @click.outside="$wire.open = false">
    <button wire:click="toggle" class="icon-btn" title="Notifications" aria-label="Notifications" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <x-icon name="bell" />
        @if($this->unreadCount > 0)
            <span class="badge">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    @if($open)
        <div class="notif-panel">
            <div class="notif-head">
                <span>Notifications</span>
                @if($this->unreadCount > 0)
                    <button wire:click="markAllAsRead" class="link" style="font-weight:400;font-size:12px">Mark all read</button>
                @endif
            </div>
            @if($this->notifications->isEmpty())
                <p style="padding:20px 12px;color:var(--muted);text-align:center">No notifications yet.</p>
            @else
                @foreach($this->notifications as $notification)
                    <a href="{{ $notification->data['url'] ?? '#' }}" wire:click="markAsRead('{{ $notification->id }}')" @class(['notif', 'unread' => ! $notification->read_at])>
                        <b>{{ $notification->data['title'] ?? 'Notification' }}</b>
                        <span>{{ $notification->data['message'] ?? '' }}</span>
                    </a>
                @endforeach
            @endif
            <div class="notif" style="text-align:center"><a class="link" href="{{ route('notifications.index') }}">View all</a></div>
        </div>
    @endif
</div>
