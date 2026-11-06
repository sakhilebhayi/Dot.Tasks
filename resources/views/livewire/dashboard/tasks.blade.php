@php $tasks = $this->data[$key]; @endphp
@if($tasks->isEmpty())
    <p class="widget-empty">Nothing here.</p>
@else
    @foreach($tasks as $task)
        <button type="button" class="widget-row" wire:key="w-{{ $key }}-{{ $task->id }}" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
            <span class="widget-title">{{ $task->title }}</span>
            <span class="widget-side">
                @if($view === 'team' && $task->assignee)<span>{{ $task->assignee->name }}</span>@endif
                <x-task-due :task="$task" />
            </span>
        </button>
    @endforeach
@endif
