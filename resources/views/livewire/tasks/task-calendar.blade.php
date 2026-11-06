<div>
    @php $canWork = auth()->user()->can('create', [\App\Models\Task::class, $taskList]); @endphp
    @include('livewire.tasks.partials.task-filters', ['extras' => 'calendar'])

    <div class="cal-bar">
        <h2 aria-live="polite">{{ $this->title }}</h2>
        <button type="button" class="icon-btn" wire:click="go(-1)" aria-label="Earlier"><x-icon name="back" /></button>
        <button type="button" class="icon-btn" wire:click="go(1)" aria-label="Later"><x-icon name="forward" /></button>
        <button type="button" class="btn small" wire:click="goToday">Today</button>
        <div class="tabs" style="margin-left:auto">
            @foreach(\App\Livewire\Tasks\TaskCalendar::RANGES as $key => $name)
                <button type="button" class="tab" aria-pressed="{{ $this->rangeName === $key ? 'true' : 'false' }}" wire:click="$set('range', '{{ $key }}')">{{ $name }}</button>
            @endforeach
        </div>
    </div>

    @error('status')<p role="alert" class="alert">{{ $message }}</p>@enderror

    <div class="cal-wrap">
        <div class="cal" style="--cal-cols: {{ $this->rangeName === 'day' ? 1 : 7 }}">
            @if($this->rangeName !== 'day')
                <div class="cal-head" aria-hidden="true">
                    @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name)<span>{{ $name }}</span>@endforeach
                </div>
            @endif
            <div @class(['cal-grid', 'tall' => $this->rangeName !== 'month']) role="list" aria-label="{{ $this->title }}">
                @foreach($this->days as $day)
                    <div @class(['cal-day', 'out' => ! $day['inRange'], 'today' => $day['today'], 'quiet' => $day['tasks']->isEmpty() && ! $day['today']]) role="listitem" aria-label="{{ $day['label'] }}" wire:key="day-{{ $day['date'] }}"
                         x-data="{ adding: false, title: '' }">
                        <div class="cal-date">
                            <b>{{ $this->rangeName === 'month' ? $day['number'] : $day['label'] }}</b>
                            @if($canWork)
                                <button type="button" class="cal-add" aria-label="Add a task on {{ $day['label'] }}" x-on:click="adding = true; $nextTick(() => $refs.line.focus())"><x-icon name="plus" /></button>
                            @endif
                        </div>
                        <div class="cal-list" data-date="{{ $day['date'] }}" @if($canWork) wire:key="list-{{ $day['date'] }}" x-data="dayList" x-on:keydown="keys($event)" @endif>
                            @foreach($day['tasks'] as $task)
                                @include('livewire.tasks.partials.calendar-card')
                            @endforeach
                        </div>
                        @if($canWork)
                            <form x-show="adding" x-cloak style="padding:0 4px 4px" x-on:submit.prevent="if (title.trim()) { $wire.addOn('{{ $day['date'] }}', title); title = ''; adding = false }">
                                <input class="input" style="min-height:26px;padding:2px 6px;font-size:12px" x-ref="line" x-model="title" maxlength="255" autocomplete="off" aria-label="New task on {{ $day['label'] }}" placeholder="Type, then Enter"
                                       x-on:keydown.escape="adding = false; title = ''" x-on:blur="if (! title.trim()) adding = false">
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        <aside class="cal-side" aria-label="Tasks with no date">
            <h3>No date</h3>
            <div class="cal-list" data-date="" @if($canWork) x-data="dayList" @endif>
                @foreach($this->undated as $task)
                    @include('livewire.tasks.partials.calendar-card')
                @endforeach
            </div>
            @if($this->undated->isEmpty())<p style="padding:4px;color:var(--muted);font-size:12px">Every task has a date.</p>@endif
        </aside>
    </div>

    <livewire:tasks.create-task :task-list="$taskList" />
    <div x-data="pulse" hidden></div>
</div>
