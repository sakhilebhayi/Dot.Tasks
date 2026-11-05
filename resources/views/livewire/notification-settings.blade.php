<div class="rows">
    @foreach(\App\Services\NotificationPreferences::EVENTS as $event => $name)
        <div class="row" wire:key="pref-{{ $event }}">
            <div class="row-main"><b style="font-weight:400;white-space:normal">{{ $name }}</b></div>
            <div class="row-end">
                <label class="pick-line" style="padding:0"><input type="checkbox" @checked($prefs->wants(auth()->user(), $event)) wire:click="toggle('{{ $event }}', 'app')"> In the app</label>
                @if(in_array($event, \App\Services\NotificationPreferences::EMAILED, true))
                    <label class="pick-line" style="padding:0"><input type="checkbox" @checked($prefs->wants(auth()->user(), $event, 'email')) wire:click="toggle('{{ $event }}', 'email')"> By email</label>
                @endif
            </div>
        </div>
    @endforeach
</div>
