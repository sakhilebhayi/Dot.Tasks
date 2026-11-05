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
    <div class="rack kanban-scroll" tabindex="0" role="region" aria-label="Task board columns">
        @foreach(\App\Livewire\Tasks\TaskBoard::COLUMNS as $status => $label)
            <div class="slot kanban-col"
                 @if($canWork)
                     x-data
                     x-on:dragover.prevent
                     x-on:drop.prevent="$wire.moveTask($event.dataTransfer.getData('taskId'), '{{ $status }}')"
                 @endif>
                <h3 class="slot-head">{{ $label }} <span class="count">{{ $this->tasksByStatus[$status]->count() }}</span></h3>
                <div class="slot-body">
                    @forelse($this->tasksByStatus[$status] as $task)
                        <div @class(['card', 'is-done' => $task->status === 'done']) wire:key="card-{{ $task->id }}"
                             @if($canWork)
                                 draggable="true"
                                 x-on:dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')"
                             @endif
                             wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
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
                            @can('move', $task)
                                {{-- The touch-friendly way to move a card. Always visible, never hover-only. --}}
                                <div style="margin-top:6px" x-on:click.stop>
                                    <label for="move-{{ $task->id }}" class="sr">Move &quot;{{ $task->title }}&quot; to</label>
                                    <select id="move-{{ $task->id }}" wire:key="move-{{ $task->id }}-{{ $moveAttempts }}"
                                            wire:change="moveTask({{ $task->id }}, $event.target.value)"
                                            class="select kanban-move" style="min-height:28px;padding:2px 6px;font-size:12px">
                                        <option value="" selected disabled>Move to...</option>
                                        @foreach(\App\Livewire\Tasks\TaskBoard::COLUMNS as $moveStatus => $moveLabel)
                                            @if($moveStatus !== $task->status)
                                                <option value="{{ $moveStatus }}">{{ $moveLabel }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                            @endcan
                        </div>
                    @empty
                        <p style="padding:12px 4px;color:var(--muted);font-size:12px;text-align:center">No tasks here.</p>
                    @endforelse
                </div>
                @if($canWork)
                    <div class="slot-foot">
                        <button class="add-line" wire:click="$dispatch('open-create-task', { status: '{{ $status }}' })"><x-icon name="plus" /> Add task</button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
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
