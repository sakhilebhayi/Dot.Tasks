<div>
    <div class="filters" style="justify-content:space-between">
        <div class="tabs" role="tablist" aria-label="Dashboards">
            @foreach($this->views as $option)
                <button type="button" role="tab" class="tab" aria-selected="{{ $view === $option ? 'true' : 'false' }}" wire:click="$set('view', '{{ $option }}')">{{ ['personal' => 'Mine', 'team' => 'Team', 'business' => 'Business'][$option] }}</button>
            @endforeach
        </div>
        <div class="page-actions">
            @if($view === 'team')
                <select class="select bare" wire:model.live="board" aria-label="Board">
                    <option value="">Every board</option>
                    @foreach($this->boards as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            @endif
            <button type="button" class="btn quiet" wire:click="$toggle('arranging')">{{ $arranging ? 'Done' : 'Arrange' }}</button>
        </div>
    </div>

    @if($arranging)
        <div class="filters">
            @foreach($this->widgets as $widget)
                @if($widget['hidden'])
                    <button type="button" class="chip" style="border:0" wire:click="toggleWidget({{ $widget['id'] }})">Show {{ $widget['name'] }}</button>
                @endif
            @endforeach
            <button type="button" class="link" wire:click="resetLayout">Reset</button>
            @if(in_array('business', $this->views, true))
                <button type="button" class="link" wire:click="shareLayout">Use this for the team</button>
            @endif
        </div>
    @endif

    <div class="widgets" @if($arranging) x-data="stepList('placeWidget')" wire:key="arranging" @endif>
        @foreach($this->widgets as $widget)
            @unless($widget['hidden'])
                <section @class(['widget', 'wide' => in_array($widget['key'], ['boards', 'workload'], true)]) data-task="{{ $widget['id'] }}" wire:key="widget-{{ $view }}-{{ $widget['id'] }}" aria-label="{{ $widget['name'] }}">
                    <div class="widget-head">
                        @if($arranging)
                            <button type="button" class="grip" aria-label="Move {{ $widget['name'] }}, use the up and down arrows" x-on:keydown="keys($event, {{ $widget['id'] }})"><x-icon name="grip" /></button>
                        @endif
                        <h2>{{ $widget['name'] }}</h2>
                        @if($arranging)
                            <button type="button" class="icon-btn" aria-label="Hide {{ $widget['name'] }}" wire:click="toggleWidget({{ $widget['id'] }})"><x-icon name="close" /></button>
                        @endif
                    </div>
                    @includeFirst(['livewire.dashboard.'.$widget['key'], 'livewire.dashboard.tasks'], ['key' => $widget['key']])
                </section>
            @endunless
        @endforeach
    </div>
</div>
