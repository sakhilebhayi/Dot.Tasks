<span role="cell" @class(['cell', 'cell-opt' => $column !== 'due'])>
    @switch($column)
        @case('status')
            @can('move', $task)
                <select class="select bare" aria-label="Status of {{ $task->title }}" wire:change="saveCell({{ $task->id }}, 'status', $event.target.value)">
                    @foreach($this->columns as $option)
                        <option value="{{ $option->id }}" @selected($task->status_id === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            @else
                {{ $this->columns->firstWhere('id', $task->status_id)?->name }}
            @endcan
            @break
        @case('assignee')
            @can('assign', $task)
                <select class="select bare" aria-label="Person on {{ $task->title }}" wire:change="saveCell({{ $task->id }}, 'assignee', $event.target.value)">
                    <option value="">Nobody</option>
                    @foreach($this->members as $member)
                        <option value="{{ $member->id }}" @selected($task->assignee_id === $member->id)>{{ $member->name }}</option>
                    @endforeach
                </select>
            @else
                {{ $task->assignee?->name ?? 'Nobody' }}
            @endcan
            @break
        @case('priority')
            @can('update', $task)
                <select class="select bare" aria-label="Priority of {{ $task->title }}" wire:change="saveCell({{ $task->id }}, 'priority', $event.target.value)">
                    @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                        <option value="{{ $p }}" @selected($task->priority === $p)>{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
            @else
                {{ ucfirst($task->priority) }}
            @endcan
            @break
        @case('due')
            @can('update', $task)
                <input @class(['ghost-input', 'overdue' => $task->isOverdue()]) type="date" aria-label="Due date of {{ $task->title }}" value="{{ $task->due_date?->toDateString() }}" wire:change="saveCell({{ $task->id }}, 'due_date', $event.target.value)">
            @else
                <x-task-due :task="$task" />
            @endcan
            @break
        @case('labels')
            @foreach($task->labels as $chip)
                <x-label-chip :label="$chip" />
            @endforeach
            @break
        @default
            @if(str_starts_with($column, 'f'))
                {{ $task->fieldValues->firstWhere('task_field_id', (int) substr($column, 1))?->value }}
            @endif
            @break
        @case('estimate')
            @can('update', $task)
                <input class="ghost-input num" type="number" min="0" aria-label="Estimate in minutes for {{ $task->title }}" value="{{ $task->estimated_minutes }}" placeholder="min" wire:change="saveCell({{ $task->id }}, 'estimated_minutes', $event.target.value)">
            @else
                <span class="num">{{ $task->estimated_minutes ? $task->estimated_minutes.'m' : '' }}</span>
            @endcan
            @break
    @endswitch
</span>
