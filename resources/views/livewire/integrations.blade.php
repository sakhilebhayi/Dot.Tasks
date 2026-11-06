<div>
    <p style="margin-bottom:12px;color:var(--muted)">{{ $linked ? $teamName.' is linked to its InfoDot company.' : $teamName.' is not linked to an InfoDot company, so nothing can be sent for it.' }}</p>
    <div class="rows">
        @foreach($platforms as $platform)
            <div class="row" wire:key="platform-{{ $loop->index }}">
                <div class="row-main">
                    <b>{{ $platform['name'] }}</b>
                    <span style="white-space:normal">{{ $platform['does'] }}@if($platform['last']) Last call: {{ $platform['last']['ok'] ? 'it worked' : $platform['last']['message'] }}@endif</span>
                </div>
                <span @class(['chip', 'go' => $platform['ready']])>{{ $platform['ready'] ? 'Set up' : 'Not set up' }}</span>
            </div>
        @endforeach
    </div>
    <p class="chart-note">Set up means this server holds the address and secret. It does not prove the other platform accepts them.</p>
</div>
