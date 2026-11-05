<div>
    <div class="filters">
        <input class="input" type="search" wire:model.live.debounce.300ms="search" placeholder="Search my tasks..." aria-label="Search my tasks">
        <select class="select bare" wire:model.live="priority" aria-label="Priority">
            <option value="">Any priority</option>
            @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                <option value="{{ $p }}">{{ ucfirst($p) }}</option>
            @endforeach
        </select>
        <select class="select bare" wire:model.live="due" aria-label="Due">
            <option value="">Any due date</option>
            @foreach(\App\Livewire\Tasks\MyTasks::DUE_STATES as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
        </select>
        @if($this->labels->isNotEmpty())
            <select class="select bare" wire:model.live="labelId" aria-label="Label">
                <option value="">Any label</option>
                @foreach($this->labels as $label)
                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                @endforeach
            </select>
        @endif
        <label style="display:inline-flex;gap:6px;align-items:center;color:var(--muted)">
            <input type="checkbox" wire:model.live="showDone"> Include done
        </label>
        @if($this->filtersActive)
            <button type="button" wire:click="clearFilters" class="link">Clear filters</button>
        @endif
    </div>

    @error('status')
        <p role="alert" class="alert">{{ $message }}</p>
    @enderror

    @if($this->tasks->isEmpty())
        <div class="empty">{{ $this->filtersActive ? 'None of your tasks match these filters.' : 'Nothing is assigned to you.' }}</div>
    @else
        <div class="rows">
            @foreach($this->tasks as $task)
                <div wire:key="mine-{{ $task->id }}" @class(['row', 'is-done' => $task->status === 'done'])>
                    @can('move', $task)
                        @if($task->status === 'done')
                            <span class="tick" role="checkbox" aria-checked="true" aria-label="Done" title="Done"></span>
                        @else
                            <button class="tick" wire:click="completeTask({{ $task->id }})" role="checkbox" aria-checked="false" title="Mark done" aria-label="Mark {{ $task->title }} done"></button>
                        @endif
                    @endcan
                    <button type="button" class="row-main" style="border:0;background:none;padding:0;text-align:left" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                        <b>{{ $task->title }}</b>
                        <span>{{ $task->taskList->name }}</span>
                    </button>
                    <span class="row-end">
                        @foreach($task->labels as $label)
                            <x-label-chip :label="$label" class="topbar-hide" />
                        @endforeach
                        <x-task-due :task="$task" />
                        <span class="chip">{{ ucfirst($task->priority) }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>
