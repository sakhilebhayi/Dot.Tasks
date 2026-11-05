<div>
    @include('livewire.tasks.partials.task-filters', ['extras' => 'list'])

    <div class="filters">
        <label class="inline-field">Group by
            <select class="select bare" wire:model.live="group">
                @foreach(\App\Livewire\Tasks\TaskTable::GROUPS as $value => $name)
                    <option value="{{ $value }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="inline-field">Sort by
            <select class="select bare" wire:change="sortOn($event.target.value)" wire:key="sort-{{ $this->sortBy }}">
                @foreach(\App\Livewire\Tasks\TaskTable::SORTS as $value => $name)
                    <option value="{{ $value }}" @selected($this->sortBy === $value)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <details class="pick">
            <summary class="btn quiet">Columns</summary>
            <div class="menu">
                @foreach(\App\Livewire\Tasks\TaskTable::COLUMNS as $key => $name)
                    <label class="pick-line"><input type="checkbox" @checked(in_array($key, $this->shown, true)) wire:click="toggleColumn('{{ $key }}')"> {{ $name }}</label>
                @endforeach
            </div>
        </details>
    </div>

    @error('status')<p role="alert" class="alert">{{ $message }}</p>@enderror
    @foreach(['title', 'due_date', 'priority', 'task'] as $field)
        @error($field)<p role="alert" class="alert">{{ $message }}</p>@enderror
    @endforeach
    @if($notice !== '')<p role="status" class="alert quiet-alert">{{ $notice }}</p>@endif

    @php
        $canWork = auth()->user()->can('create', [\App\Models\Task::class, $taskList]);
        $track = collect($this->shown)->map(fn ($c) => ['status' => '124px', 'assignee' => '150px', 'priority' => '104px', 'due' => '132px', 'labels' => '150px', 'estimate' => '84px'][$c])->implode(' ');
        $columns = ($canWork ? '28px ' : '').($this->canDrag ? '20px ' : '').'minmax(220px, 1fr) '.$track.' 32px';
    @endphp

    @if($this->tasks->isEmpty() && ! $this->canDropInGroups)
        <div class="empty">{{ $this->filtersActive ? 'No tasks match these filters.' : 'No tasks yet.' }}</div>
    @else
        <div class="grid-scroll" data-rack role="table" aria-label="{{ $taskList->name }} tasks" style="--cols: {{ $columns }}">
            <div class="trow thead" role="row">
                @if($canWork)
                    <span role="columnheader" class="cell"><input type="checkbox" aria-label="Select every task shown" wire:click="toggleAll" @checked($selected !== [] && count($selected) === $this->tasks->count())></span>
                @endif
                @if($this->canDrag)<span role="columnheader" class="cell"><span class="sr">Move</span></span>@endif
                <span role="columnheader" class="cell"><button type="button" class="thead-sort" wire:click="sortOn('title')">Title</button></span>
                @foreach($this->shown as $column)
                    <span role="columnheader" @class(['cell', 'cell-opt' => $column !== 'due'])>
                        @if(in_array($column, ['due', 'priority'], true))
                            <button type="button" class="thead-sort" wire:click="sortOn('{{ $column }}')">{{ \App\Livewire\Tasks\TaskTable::COLUMNS[$column] }}</button>
                        @else
                            {{ \App\Livewire\Tasks\TaskTable::COLUMNS[$column] }}
                        @endif
                    </span>
                @endforeach
                <span role="columnheader" class="cell"><span class="sr">Open</span></span>
            </div>
            @foreach($this->groups as $group)
                <div role="rowgroup" wire:key="group-{{ $this->groupBy }}-{{ $group['key'] }}">
                    @if($group['name'] !== '')
                        <h3 class="group-head">{{ $group['name'] }} <span class="count">{{ $group['tasks']->count() }}</span></h3>
                    @endif
                    <div class="tgroup" data-name="{{ $group['name'] }}"
                         @if($this->canDrag)
                             data-list data-handle=".grip" wire:key="list-{{ $this->groupBy }}-{{ $group['key'] }}" x-data="boardColumn" x-on:keydown="keys($event)"
                             @if($this->groupBy === 'status') data-status="{{ $group['key'] }}" data-category="{{ $group['category'] }}" @else data-lane="{{ $group['key'] }}" @endif
                         @endif>
                        @foreach($group['tasks'] as $task)
                            <div @class(['trow', 'is-done' => $task->status === 'done']) role="row" wire:key="row-{{ $task->id }}-{{ $edits }}"
                                 data-task="{{ $task->id }}" data-status="{{ $task->status_id }}" data-title="{{ $task->title }}" tabindex="-1" x-data="{ open: false }"
                                 @if($task->blockedBy->contains(fn ($b) => $b->status !== 'done')) data-blocked @endif>
                                @if($canWork)
                                    <span role="cell" class="cell"><input type="checkbox" aria-label="Select {{ $task->title }}" value="{{ $task->id }}" wire:model.live="selected"></span>
                                @endif
                                @if($this->canDrag)
                                    <span role="cell" class="cell"><button type="button" class="grip" aria-label="Move {{ $task->title }}" aria-describedby="drag-help"><x-icon name="grip" /></button></span>
                                @endif
                                <span role="cell" class="cell cell-title">
                                    @if($task->subtasks->isNotEmpty())
                                        <button type="button" class="expand" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-label="Subtasks of {{ $task->title }}"><x-icon name="chevron" /></button>
                                    @endif
                                    @can('update', $task)
                                        <input class="ghost-input" aria-label="Title of {{ $task->title }}" value="{{ $task->title }}" maxlength="255" wire:change="saveCell({{ $task->id }}, 'title', $event.target.value)">
                                    @else
                                        <span>{{ $task->title }}</span>
                                    @endcan
                                    @if($task->blockedBy->contains(fn ($b) => $b->status !== 'done'))
                                        <span class="blocked-line" style="margin:0">Blocked</span>
                                    @endif
                                    @if($task->subtasks->isNotEmpty())
                                        <span class="num" style="color:var(--muted);font-size:12px;white-space:nowrap">{{ $task->subtasks->where('status', 'done')->count() }}/{{ $task->subtasks->count() }}</span>
                                    @endif
                                </span>
                                @foreach($this->shown as $column)
                                    @include('livewire.tasks.partials.table-cell')
                                @endforeach
                                <span role="cell" class="cell"><button type="button" class="icon-btn" aria-label="Open {{ $task->title }}" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })"><x-icon name="forward" /></button></span>
                                @if($task->subtasks->isNotEmpty())
                                    <div class="subrows" x-show="open" x-cloak>
                                        @foreach($task->subtasks as $sub)
                                            <button type="button" @class(['subrow', 'is-done' => $sub->status === 'done']) wire:key="subrow-{{ $sub->id }}" wire:click="$dispatch('open-task', { taskId: {{ $sub->id }} })">
                                                <span class="tick" style="width:14px;height:14px" aria-hidden="true" aria-checked="{{ $sub->status === 'done' ? 'true' : 'false' }}"></span> {{ $sub->title }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($selected !== [])
        <div class="bulk" role="region" aria-label="Change what is selected">
            <b class="num">{{ count($selected) }}</b> selected
            <select class="select bare" aria-label="Move what is selected" wire:change="bulk('status', $event.target.value)" wire:key="bulk-status-{{ $edits }}">
                <option value="" selected disabled>Status</option>
                @foreach($this->columns as $column)
                    <option value="{{ $column->id }}">{{ $column->name }}</option>
                @endforeach
            </select>
            <select class="select bare" aria-label="Assign what is selected" wire:change="bulk('assignee', $event.target.value)" wire:key="bulk-person-{{ $edits }}">
                <option value="" selected disabled>Person</option>
                @foreach($this->members as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
            <select class="select bare" aria-label="Set the priority of what is selected" wire:change="bulk('priority', $event.target.value)" wire:key="bulk-priority-{{ $edits }}">
                <option value="" selected disabled>Priority</option>
                @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                    <option value="{{ $p }}">{{ ucfirst($p) }}</option>
                @endforeach
            </select>
            <input class="input" type="date" aria-label="Set the due date of what is selected" style="min-height:30px;width:auto" wire:change="bulk('due_date', $event.target.value)" wire:key="bulk-due-{{ $edits }}">
            <button type="button" class="btn small" wire:click="bulk('archive')" wire:confirm="Archive what is selected, with any subtasks? You can restore them from Archived.">Archive</button>
            <button type="button" class="link" wire:click="$set('selected', [])">Clear</button>
        </div>
    @endif

    <livewire:tasks.create-task :task-list="$taskList" />
    <div x-data="pulse" hidden></div>
</div>
