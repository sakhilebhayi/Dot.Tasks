<div>
    @php
        $day = \App\Livewire\Tasks\TaskTimeline::DAY_WIDTH;
        $row = \App\Livewire\Tasks\TaskTimeline::ROW_HEIGHT;
        $days = \App\Livewire\Tasks\TaskTimeline::DAYS;
    @endphp
    @include('livewire.tasks.partials.task-filters', ['extras' => 'timeline'])

    <div class="cal-bar">
        <h2 aria-live="polite">{{ $this->first->format('j M') }} to {{ $this->first->copy()->addDays($days - 1)->format('j M Y') }}</h2>
        <button type="button" class="icon-btn" wire:click="go(-4)" aria-label="Earlier"><x-icon name="back" /></button>
        <button type="button" class="icon-btn" wire:click="go(4)" aria-label="Later"><x-icon name="forward" /></button>
        <button type="button" class="btn small" wire:click="goToday">Today</button>
    </div>

    @error('status')<p role="alert" class="alert">{{ $message }}</p>@enderror

    @if($this->rows->isEmpty())
        <div class="empty">{{ $this->filtersActive ? 'No tasks match these filters.' : 'No tasks in these weeks.' }}</div>
    @else
        <div class="tl" tabindex="0" role="region" aria-label="Timeline" style="--tl-day: {{ $day }}px; --tl-row: {{ $row }}px; --tl-width: {{ $day * $days }}px"
             x-data="timeline({{ $day }})" x-on:pointerdown="down($event)" x-on:pointermove="move($event)" x-on:pointerup="up($event)" x-on:keydown="keys($event)">
            <div class="tl-inner">
                <div class="tl-row tl-head">
                    <div class="tl-name">Task</div>
                    <div class="tl-track">
                        @foreach($this->weeks as $week)
                            <span class="tl-week" style="left:{{ $week['left'] }}px">{{ $week['label'] }}</span>
                        @endforeach
                        @if($this->todayLeft !== null)<span class="tl-today" style="left:{{ $this->todayLeft }}px"></span>@endif
                    </div>
                </div>
                @foreach($this->rows as $bar)
                    @php $task = $bar['task']; @endphp
                    <div class="tl-row" wire:key="tl-{{ $task->id }}">
                        <div class="tl-name"><button type="button" title="{{ $task->title }}" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">{{ $task->title }}</button></div>
                        <div class="tl-track">
                            @if($this->todayLeft !== null)<span class="tl-today" style="left:{{ $this->todayLeft }}px"></span>@endif
                            @if($bar['left'] !== null)
                                @can('update', $task)
                                    <button type="button" @class(['bar', 'is-done' => $task->status === 'done', 'late' => $bar['late']]) style="left:{{ $bar['left'] }}px;width:{{ $bar['width'] }}px" data-bar
                                            data-task="{{ $task->id }}" data-title="{{ $task->title }}" data-start="{{ $bar['start'] }}" data-due="{{ $bar['due'] }}" aria-describedby="bar-help"
                                            aria-label="{{ $task->title }}, {{ $bar['start'] }} to {{ $bar['due'] }}">
                                        <span class="bar-edge" data-edge="start"></span>{{ $bar['width'] >= 96 ? $task->title : '' }}<span class="bar-edge" data-edge="due"></span>
                                    </button>
                                @else
                                    <span @class(['bar', 'is-done' => $task->status === 'done', 'late' => $bar['late']]) style="left:{{ $bar['left'] }}px;width:{{ $bar['width'] }}px;cursor:default" title="{{ $task->title }}">{{ $bar['width'] >= 96 ? $task->title : '' }}</span>
                                @endcan
                            @else
                                @can('update', $task)
                                    <button type="button" class="tl-pick" aria-label="Give {{ $task->title }} a date" title="Click a day to schedule this"
                                            x-on:click="$wire.schedule({{ $task->id }}, Math.floor(($event.clientX - $el.getBoundingClientRect().left) / {{ $day }}))"></button>
                                @endcan
                            @endif
                        </div>
                    </div>
                @endforeach
                <svg class="tl-lines" width="{{ $day * $days }}" height="{{ $row * $this->rows->count() }}" aria-hidden="true">
                    @foreach($this->lines as $line)
                        <path d="{{ $line['path'] }}" @class(['late' => $line['late']])></path>
                    @endforeach
                </svg>
            </div>
        </div>
    @endif

    <livewire:tasks.create-task :task-list="$taskList" />
    <div x-data="pulse" hidden></div>
</div>
