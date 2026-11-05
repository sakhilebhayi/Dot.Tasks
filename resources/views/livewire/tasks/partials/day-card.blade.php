<div @class(['day-card', 'is-done' => $task->status === 'done']) wire:key="{{ $planned ? 'plan' : ($key ?? 'x') }}-{{ $task->id }}" data-task="{{ $task->id }}" data-title="{{ $task->title }}" data-date="{{ $task->due_date?->toDateString() }}">
    @if($planned)
        <button type="button" class="grip" aria-label="Move {{ $task->title }}, hold Alt and use the up and down arrows"><x-icon name="grip" /></button>
    @endif
    @can('move', $task)
        <button type="button" class="tick" role="checkbox" aria-checked="{{ $task->status === 'done' ? 'true' : 'false' }}" aria-label="Mark {{ $task->title }} done" wire:click="toggleDone({{ $task->id }})"></button>
    @endcan
    <button type="button" class="day-title" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
        <b>{{ $task->title }}</b>
        <span>{{ $task->taskList->is_inbox ? 'Inbox' : $task->taskList->name }}@if($task->isBlocked()) &middot; Blocked @endif</span>
    </button>
    <x-task-due :task="$task" />
    @if($planned)
        <button type="button" class="icon-btn" aria-label="Take {{ $task->title }} off the plan" wire:click="unplan({{ $task->id }})"><x-icon name="close" /></button>
    @else
        <button type="button" class="icon-btn" aria-label="Add {{ $task->title }} to the plan" wire:click="planIt({{ $task->id }})"><x-icon name="plus" /></button>
    @endif
</div>
