<div>
    @php $canWork = auth()->user()->can('create', [\App\Models\Task::class, $taskList]); @endphp
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
    @include('livewire.tasks.partials.task-filters', ['extras' => 'board'])

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

    <div x-data="pulse" hidden></div>

    <div x-data="boardLive({{ $taskList->id }})" hidden></div>
</div>
