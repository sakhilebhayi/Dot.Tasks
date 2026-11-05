<div class="rack kanban-scroll" data-rack tabindex="0" role="region" aria-label="{{ $lane['name'] ?: 'Task board' }} columns">
    @foreach(\App\Livewire\Tasks\TaskBoard::COLUMNS as $status => $label)
        <div class="slot kanban-col" wire:key="slot-{{ $lane['key'] }}-{{ $status }}">
            <h3 class="slot-head">{{ $label }} <span class="count">{{ $lane['columns'][$status]->count() }}</span></h3>
            <div class="slot-body" data-status="{{ $status }}" data-name="{{ $label }}" @if($lane['key'] !== '') data-lane="{{ $lane['key'] }}" @endif
                 @if($canWork) wire:key="cards-{{ $lane['key'] }}-{{ $status }}" x-data="boardColumn" x-on:keydown="keys($event)" @endif>
                @foreach($lane['columns'][$status] as $task)
                    <div @class(['card', 'is-done' => $task->status === 'done']) wire:key="card-{{ $task->id }}" tabindex="0" role="button"
                         data-task="{{ $task->id }}" data-title="{{ $task->title }}" @if($canWork) aria-describedby="drag-help" @endif
                         @if($task->blockedBy->contains(fn ($b) => $b->status !== 'done')) data-blocked @endif
                         wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })"
                         x-on:keydown.enter="$dispatch('open-task', { taskId: {{ $task->id }} })">
                        @if(in_array($task->priority, ['urgent', 'high', 'low'], true))
                            <span class="notch {{ $task->priority }}" title="{{ ucfirst($task->priority) }} priority"></span>
                        @endif
                        <p class="card-title">{{ $task->title }}@if($task->isRecurring()) <span class="sr">Repeats</span><x-icon name="repeat" style="width:12px;height:12px;vertical-align:-1px;color:var(--muted)" />@endif</p>
                        @if($task->labels->isNotEmpty())
                            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">
                                @foreach($task->labels as $chip)
                                    <x-label-chip :label="$chip" />
                                @endforeach
                            </div>
                        @endif
                        @if($task->blockedBy->contains(fn ($b) => $b->status !== 'done'))
                            <p class="blocked-line" title="Cannot be marked done until its blockers are">Blocked</p>
                        @endif
                        <div class="card-meta">
                            <span class="sr">{{ ucfirst($task->priority) }}</span>
                            <x-task-due :task="$task" />
                            @if($task->subtasks->isNotEmpty())
                                <span class="num">{{ $task->subtasks->where('status', 'done')->count() }}/{{ $task->subtasks->count() }} subtasks</span>
                            @endif
                            @if($task->assignee)
                                <span class="person" title="{{ $task->assignee->name }}">{{ strtoupper(substr($task->assignee->name, 0, 1)) }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if($canWork && $status !== 'done')
                <div class="slot-foot" wire:key="foot-{{ $lane['key'] }}-{{ $status }}" x-data="{ adding: false, title: '' }">
                    <button type="button" class="add-line" x-show="! adding" x-on:click="adding = true; $nextTick(() => $refs.line.focus())"><x-icon name="plus" /> Add task</button>
                    <form x-show="adding" x-cloak x-on:submit.prevent="if (title.trim()) { $wire.addHere('{{ $status }}', title); title = '' }">
                        <input class="input" x-ref="line" x-model="title" maxlength="255" autocomplete="off" aria-label="New task in {{ $label }}" placeholder="Type, then Enter"
                               x-on:keydown.escape="adding = false; title = ''" x-on:blur="if (! title.trim()) adding = false">
                    </form>
                </div>
            @endif
        </div>
    @endforeach
</div>
