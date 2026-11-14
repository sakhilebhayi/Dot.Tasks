@php
    $key = $group['key'];
    $tasks = $group['tasks'];
    $tone = ['overdue' => 'late', 'attention' => 'late', 'blocked' => 'wait', 'recent' => 'done'][$key] ?? '';
    $capped = $tasks && in_array($key, ['overdue', 'blocked', 'due_soon', 'no_date', 'awaiting', 'high', 'attention', 'unassigned', 'upcoming'], true) && $tasks->count() >= 8;
@endphp
<section @class(['d-group', 'compact' => in_array($key, ['no_date', 'upcoming', 'recent', 'awaiting', 'high'], true), $tone => $tone !== ''])
         data-task="{{ $group['id'] }}" wire:key="widget-{{ $view }}-{{ $group['id'] }}" aria-labelledby="g-{{ $group['id'] }}" style="--g:{{ min($index, 4) }}">
    <div class="d-head">
        @if($arranging)
            <button type="button" class="grip" aria-label="Move {{ $group['name'] }}, use the up and down arrows" x-on:keydown="keys($event, {{ $group['id'] }})"><x-icon name="grip" /></button>
        @endif
        <h2 id="g-{{ $group['id'] }}">{{ $group['name'] }}</h2>
        @if($tasks)
            <span class="count num" @if($capped) title="The first 8 are listed" @endif>{{ $tasks->count() }}{{ $capped ? '+' : '' }}</span>
        @endif
        @if(! $arranging && $key === 'upcoming' && $view === 'personal')
            <a class="more" href="{{ route('tasks.mine') }}">All in My tasks</a>
        @elseif(! $arranging && $key === 'upcoming' && $view === 'team')
            <a class="more" href="{{ route('tasks.team', ['show' => 'week']) }}">Open in Who holds what</a>
        @endif
        @if($arranging)
            <button type="button" class="icon-btn" style="margin-left:auto" aria-label="Hide {{ $group['name'] }}" wire:click="toggleWidget({{ $group['id'] }})"><x-icon name="close" /></button>
        @endif
    </div>
    @includeFirst(['livewire.dashboard.'.$key, 'livewire.dashboard.tasks'], ['key' => $key, 'tasks' => $tasks])
</section>
