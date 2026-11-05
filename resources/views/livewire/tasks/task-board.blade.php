<div>
    @php $canWork = auth()->user()->can('create', [\App\Models\Task::class, $taskList]); @endphp
    <div class="filters" style="justify-content:space-between">
        <div class="tabs" role="tablist" aria-label="Board view">
            <button type="button" role="tab" class="tab" aria-selected="{{ $this->showingArchived ? 'false' : 'true' }}" wire:click="$set('show', '')">Board</button>
            <button type="button" role="tab" class="tab" aria-selected="{{ $this->showingArchived ? 'true' : 'false' }}" wire:click="$set('show', 'archived')">Archived</button>
        </div>
        @can('archive', $taskList)
            <button type="button" class="btn quiet" wire:click="archiveBoard"
                    wire:confirm="Archive this board? It disappears for everyone, and so do its tasks in Today, reminders and escalation. You can restore it from the dashboard.">Archive board</button>
        @endcan
    </div>

    @if($this->showingArchived)
        @if($this->archivedTasks->isEmpty())
            <div class="empty">Nothing is archived on this board.</div>
        @else
            @error('restore')<p class="alert">{{ $message }}</p>@enderror
            @error('delete')<p class="alert">{{ $message }}</p>@enderror
            <div class="rows">
                @foreach($this->archivedTasks as $archived)
                    <div class="row" wire:key="archived-{{ $archived->id }}">
                        <div class="row-main">
                            <b>{{ $archived->title }}</b>
                            <span>Archived {{ $archived->archived_at->diffForHumans() }} @if($archived->parent) &middot; subtask of {{ $archived->parent->title }} @endif</span>
                        </div>
                        <div class="row-end">
                            @foreach($archived->labels as $label)
                                <x-label-chip :label="$label" class="topbar-hide" />
                            @endforeach
                            @can('restore', $archived)
                                <button type="button" class="btn small" wire:click="restoreTask({{ $archived->id }})">Restore</button>
                            @endcan
                            @can('delete', $archived)
                                <button type="button" class="btn small danger" wire:click="deleteTask({{ $archived->id }}, true)"
                                        wire:confirm="Delete &quot;{{ $archived->title }}&quot; and any subtasks? An admin or the owner can restore it from Removed.">Delete</button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @else
    @error('status')
        <p role="alert" class="alert">{{ $message }}</p>
    @enderror
    <div class="filters">
        <input class="input" type="search" wire:model.live.debounce.300ms="search" placeholder="Search tasks..." aria-label="Search tasks">
        <select class="select bare" wire:model.live="assignee" aria-label="Assignee">
            <option value="">Anyone</option>
            <option value="me">Assigned to me</option>
            <option value="none">Unassigned</option>
            @foreach($this->members as $member)
                @if($member->id !== auth()->id())
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endif
            @endforeach
        </select>
        <select class="select bare" wire:model.live="priority" aria-label="Priority">
            <option value="">Any priority</option>
            @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                <option value="{{ $p }}">{{ ucfirst($p) }}</option>
            @endforeach
        </select>
        <select class="select bare" wire:model.live="due" aria-label="Due">
            <option value="">Any due date</option>
            @foreach(\App\Livewire\Tasks\TaskBoard::DUE_STATES as $value => $text)
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
        <select class="select bare" wire:model.live="lanes" aria-label="Group into lanes">
            <option value="">No lanes</option>
            <option value="assignee">Lanes by person</option>
            <option value="priority">Lanes by priority</option>
        </select>
        @if($this->filtersActive)
            <button type="button" wire:click="clearFilters" class="link">Clear filters</button>
        @endif
        @can('create', [\App\Models\Task::class, $taskList])
            <button class="btn primary" style="margin-left:auto" wire:click="$dispatch('open-create-task', { status: 'todo' })">
                <x-icon name="plus" /> Add task
            </button>
        @endcan
    </div>

    @if(collect($this->tasksByStatus)->every(fn ($c) => $c->isEmpty()))
        <div class="empty">
            @if($this->filtersActive)
                @if(trim($search) !== '' && $assignee === '' && $priority === '' && $labelId === '' && $due === '')
                    No tasks match "{{ $search }}".
                @else
                    No tasks match these filters.
                @endif
            @else
                No tasks yet.@if($canWork) Add your first task to get started.@endif
            @endif
        </div>
    @else
    @foreach($this->swimlanes as $lane)
        @if($this->lanes === '')
            @include('livewire.tasks.partials.board-rack', ['lane' => $lane, 'canWork' => $canWork])
        @else
            <details class="lane" open wire:key="lane-{{ $lane['key'] }}">
                <summary><x-icon name="chevron" /> {{ $lane['name'] }} <span class="count">{{ $lane['count'] }}</span></summary>
                @include('livewire.tasks.partials.board-rack', ['lane' => $lane, 'canWork' => $canWork])
            </details>
        @endif
    @endforeach
    @endif
    @endif

    <livewire:tasks.create-task :task-list="$taskList" />

    <script>
        document.addEventListener('livewire:init', () => {
            if (! window.Echo) {
                return; // BROADCAST_CONNECTION isn't reverb (e.g. local "log"/tests) -- nothing to subscribe to.
            }

            window.Echo.private(`task-list.{{ $taskList->id }}`)
                .listen('.board.updated', () => {
                    $wire.call('refreshBoard');
                });
        });
    </script>
</div>
