@php $sections = $this->sections; @endphp
<div class="ov">
    <div class="ov-bar d-in" style="--i:4">
        <div class="tabs" role="tablist" aria-label="Dashboards" x-data="tabKeys" x-on:keydown="keys($event)">
            @foreach($this->views as $option)
                <button type="button" role="tab" class="tab" tabindex="{{ $view === $option ? '0' : '-1' }}" aria-selected="{{ $view === $option ? 'true' : 'false' }}" wire:click="$set('view', '{{ $option }}')">{{ ['personal' => 'Mine', 'team' => 'Team', 'business' => 'Business'][$option] }}</button>
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
        <div class="filters ov-arrange-bar">
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

    <div wire:loading.class="is-loading" wire:target="view, board" class="ov-view" wire:key="view-{{ $view }}">
        @if($view === 'team' && ! $this->narrowed)
            <div class="facts" aria-label="Team figures, counted from the team's boards">
                @foreach([['boards', $this->boards->count(), 'boards'], ['tasks', $this->counts['total'], 'tasks'], ['in progress', $this->counts['in_progress'], 'in progress'], ['due today', $this->counts['due_today'], 'due today'], ['overdue', $this->counts['overdue'], 'overdue'], ['finished this week', $this->counts['completed_week'], 'finished this week'], ['finished', $this->counts['done'], 'finished']] as $i => [$name, $value, $label])
                    <span @class(['fact', 'late' => $name === 'overdue' && $value > 0]) style="--r:{{ $i }}"><b class="num">{{ $value }}</b> {{ $label }}</span>
                @endforeach
            </div>
        @endif

        @if($arranging)
            <div class="ov-arrange" x-data="stepList('placeWidget')" wire:key="arranging">
                @foreach($sections['groups'] as $group)
                    @include('livewire.dashboard.group', ['group' => $group, 'index' => $loop->index])
                @endforeach
            </div>
        @else
            <div @class(['ov-body', 'is-team' => $view !== 'personal'])>
                <div class="ov-col">
                    @if($view === 'personal')
                        @include('livewire.dashboard.week')
                    @endif
                    @if($view === 'team' && ! $this->narrowed)
                        @include('livewire.dashboard.board-health', ['boards' => $this->data['boards'], 'title' => 'Board health'])
                    @endif
                    @foreach($sections['groups'] as $group)
                        @unless($group['side'])
                            @include('livewire.dashboard.group', ['group' => $group, 'index' => $loop->index])
                        @endunless
                    @endforeach
                    @if($sections['checked'] !== [])
                        <p class="ov-checked">Also checked, with nothing more to show: {{ implode(', ', $sections['checked']) }}.</p>
                    @endif
                </div>
                <div class="ov-col">
                    @foreach($sections['groups'] as $group)
                        @if($group['side'])
                            @include('livewire.dashboard.group', ['group' => $group, 'index' => $loop->index])
                        @endif
                    @endforeach
                    @if($view === 'personal')
                        @include('livewire.dashboard.boards-ledger')
                    @endif
                </div>
            </div>
        @endif

        <p class="ov-foot">
            @if($view === 'personal')
                <span>{{ $this->boardRows->count() }} {{ Str::plural('board', $this->boardRows->count()) }}</span>
                <span>{{ $this->foot['visible'] }} {{ Str::plural('task', $this->foot['visible']) }} you can see</span>
                @if($this->foot['removed'] > 0)
                    <a href="{{ route('removed.index') }}">{{ $this->foot['removed'] }} removed {{ Str::plural('task', $this->foot['removed']) }}</a>
                @endif
            @elseif($view === 'team')
                <span>{{ $this->boards->count() }} {{ Str::plural('board', $this->boards->count()) }}</span>
                <span>Counted from the team's boards. Private Inboxes are never included.</span>
            @else
                <span>Admins and the owner see this view.</span>
            @endif
        </p>
    </div>
</div>
