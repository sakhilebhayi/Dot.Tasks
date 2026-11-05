<div @class(['cal-card', 'is-done' => $task->status === 'done', 'late' => $task->status !== 'done' && $task->due_date?->isPast() && ! $task->due_date?->isToday()])
     wire:key="cal-{{ $task->id }}" tabindex="0" role="button" title="{{ $task->title }}" data-task="{{ $task->id }}" data-title="{{ $task->title }}" data-date="{{ $task->due_date?->toDateString() }}"
     @if($canWork) aria-describedby="date-help" @endif
     wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })" x-on:keydown.enter="$dispatch('open-task', { taskId: {{ $task->id }} })">
    @if(in_array($task->priority, ['urgent', 'high'], true))<span class="notch {{ $task->priority }}" title="{{ ucfirst($task->priority) }} priority"></span>@endif
    {{ $task->title }}
</div>
