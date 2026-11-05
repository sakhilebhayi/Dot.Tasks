<x-app-layout title="Notifications">
    <div class="page narrow">
        <div class="page-head">
            <div>
                <h1>Notifications</h1>
                <p>Task assignments, comments, and due-date reminders.</p>
            </div>
            <div class="page-actions"><a class="btn" href="{{ route('notifications.settings') }}">Settings</a></div>
        </div>

        @if($notifications->isEmpty())
            <div class="empty">
                <p>No notifications yet</p>
                <p>Task assignments and due-soon reminders will show up here.</p>
            </div>
        @else
            <div class="rows">
                @foreach($notifications as $notification)
                    <a class="row" href="{{ $notification->data['url'] ?? '#' }}">
                        @if(!$notification->read_at)
                            <span class="swatch" style="background:var(--go);border-radius:50%"></span>
                        @endif
                        <span class="row-main">
                            <b>{{ $notification->data['title'] ?? 'Notification' }}</b>
                            <span>{{ $notification->data['message'] ?? '' }}</span>
                        </span>
                        <span class="row-end">{{ $notification->created_at->diffForHumans() }}</span>
                    </a>
                @endforeach
            </div>
            <div style="margin-top:12px">{{ $notifications->links() }}</div>
        @endif
    </div>
</x-app-layout>
