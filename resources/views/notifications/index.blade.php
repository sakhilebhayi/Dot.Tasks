<x-app-layout>
    <div class="page-pad">
        <div style="margin-bottom:2rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Notifications</h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">Task assignments, comments, and due-date reminders.</p>
        </div>

        @if($notifications->isEmpty())
            <div style="background:rgba(20,20,22,0.9);border:1px solid rgba(255,255,255,0.07);border-radius:1rem;padding:4rem 2rem;text-align:center;">
                <div style="width:56px;height:56px;border-radius:14px;background:rgba(34,197,94,0.12);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:#22c55e;">notifications_off</span>
                </div>
                <p style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 0.5rem;">No notifications yet</p>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Task assignments and due-soon reminders will show up here.</p>
            </div>
        @else
            <div style="background:rgba(20,20,22,0.9);border:1px solid rgba(255,255,255,0.07);border-radius:1rem;overflow:hidden;">
                @foreach($notifications as $notification)
                    <a href="{{ $notification->data['url'] ?? '#' }}" style="display:block;padding:1rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.06);text-decoration:none;{{ $notification->read_at ? 'opacity:0.6;' : '' }}">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:0.25rem;">
                            @if(!$notification->read_at)
                                <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;"></span>
                            @endif
                            <span style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;">{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span style="margin-left:auto;font-size:0.7rem;color:#52525b;">{{ $notification->created_at->diffForHumans() }}</span>
                        </div>
                        <p style="font-size:0.78rem;color:#a1a1aa;margin:0;">{{ $notification->data['message'] ?? '' }}</p>
                    </a>
                @endforeach
            </div>

            <div style="margin-top:1.5rem;">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
