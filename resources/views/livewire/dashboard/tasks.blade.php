@php
    $empty = [
        'upcoming' => $view === 'team' ? 'Nothing due in the next 7 days.' : 'Nothing due in the next 14 days.',
        'recent' => 'Nothing finished yet.',
    ][$key] ?? 'Nothing here.';
    $today = \App\Support\Today::date();
@endphp
@if($tasks->isEmpty())
    <p class="d-empty">{{ $empty }}</p>
@else
    <div class="d-rows">
        @foreach($tasks as $task)
            @php
                $waiting = $task->firstBlocker()?->title;
                $who = $view === 'team' ? ($task->assignee?->name ?? 'Nobody on it') : null;
                $parts = array_filter([
                    $task->taskList?->is_inbox ? 'Inbox' : $task->taskList?->name,
                    $who,
                    in_array($task->priority, ['urgent', 'high', 'low'], true) ? $task->priority : null,
                    ['review' => 'in review', 'in_progress' => 'in progress'][$task->status] ?? null,
                    $key === 'attention' && $task->isOverdue() ? $task->due_date->diffInDays($today).' days late' : null,
                ]);
            @endphp
            <button type="button" @class(['d-row', 'has-notch' => in_array($task->priority, ['urgent', 'high'], true)]) wire:key="w-{{ $key }}-{{ $task->id }}" style="--r:{{ min($loop->index, 7) }}"
                    wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                @if(in_array($task->priority, ['urgent', 'high'], true))<span class="notch {{ $task->priority }}" aria-hidden="true"></span>@endif
                <span class="d-main">
                    <b>{{ $task->title }}</b>
                    @if($waiting)
                        <span class="wait">{{ $view === 'team' ? $who.' · waiting on: ' : 'Waiting on ' }}{{ $waiting }}</span>
                    @else
                        <span>{{ implode(' · ', $parts) }}</span>
                    @endif
                </span>
                <span class="d-cell">
                    @if($key === 'recent')
                        <span class="num">{{ $task->completed_at?->format('M j') }}</span>
                    @else
                        <x-task-due :task="$task" />
                    @endif
                </span>
            </button>
        @endforeach
    </div>
@endif
