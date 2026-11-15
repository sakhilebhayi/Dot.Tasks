<div x-data="{ shut: false }" @click.outside="shut = true; $wire.open = false" @keydown.escape.window="shut = true; $wire.open = false" class="tk-bell-wrap">
    {{-- The shared header's bell look (dh-icon-btn dh-bell, 44px, hidden under 480px where the menu carries a Notifications link), kept as a button: it opens the panel below. --}}
    <button wire:click="toggle" @click="shut = false" class="dh-icon-btn dh-bell" title="Notifications" aria-label="{{ $this->unreadCount > 0 ? 'Notifications, '.$this->unreadCount.' unread' : 'Notifications' }}" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-haspopup="dialog">
        <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4"/></svg>
        @if($this->unreadCount > 0)
            <span class="dh-badge" aria-hidden="true">{{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}</span>
        @endif
    </button>

    @if($open)
        <div class="notif-panel" x-show="! shut">
            <div class="notif-head">
                <span>Notifications</span>
                @if($this->unreadCount > 0)
                    <button wire:click="markAllAsRead" class="link" style="font-weight:400;font-size:var(--dot-text-caption-size)">Mark all read</button>
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
