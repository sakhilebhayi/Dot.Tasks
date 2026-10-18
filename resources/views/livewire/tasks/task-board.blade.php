<div>
    @php $canWork = auth()->user()->can('create', [\App\Models\Task::class, $taskList]); @endphp
    {{-- View switch: the live board, or what has been archived --}}
    <div class="flex items-center gap-1 mb-3 text-sm" role="tablist" aria-label="Board view">
        <button type="button" role="tab" aria-selected="{{ $this->showingArchived ? 'false' : 'true' }}" wire:click="$set('show', '')"
                class="px-3 py-1 rounded-md {{ $this->showingArchived ? 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' : 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white font-medium' }}">Board</button>
        <button type="button" role="tab" aria-selected="{{ $this->showingArchived ? 'true' : 'false' }}" wire:click="$set('show', 'archived')"
                class="px-3 py-1 rounded-md {{ $this->showingArchived ? 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white font-medium' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}">Archived</button>
        @can('archive', $taskList)
            <button type="button" wire:click="archiveBoard"
                    wire:confirm="Archive this board? It disappears for everyone, and so do its tasks in Today, reminders and escalation. You can restore it from the dashboard."
                    class="ml-auto px-3 py-1 rounded-md text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400">Archive board</button>
        @endcan
    </div>

    @if($this->showingArchived)
        @if($this->archivedTasks->isEmpty())
            <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">inventory_2</span>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Nothing is archived on this board.</p>
            </div>
        @else
            @error('restore')<p class="mb-2 text-sm text-red-600">{{ $message }}</p>@enderror
            @error('delete')<p class="mb-2 text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="space-y-2">
                @foreach($this->archivedTasks as $archived)
                    <div wire:key="archived-{{ $archived->id }}" class="flex items-center gap-3 bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-900 dark:text-white font-medium truncate">{{ $archived->title }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                Archived {{ $archived->archived_at->diffForHumans() }}
                                @if($archived->parent) &middot; subtask of {{ $archived->parent->title }} @endif
                            </p>
                        </div>
                        @foreach($archived->labels as $label)
                            <x-label-chip :label="$label" class="hidden sm:inline-flex" />
                        @endforeach
                        @can('restore', $archived)
                            <button type="button" wire:click="restoreTask({{ $archived->id }})" class="px-3 py-1 text-xs font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600">Restore</button>
                        @endcan
                        @can('delete', $archived)
                            <button type="button" wire:click="deleteTask({{ $archived->id }}, true)"
                                    wire:confirm="Delete &quot;{{ $archived->title }}&quot; and any subtasks? It cannot be restored from the app."
                                    class="px-3 py-1 text-xs font-medium text-red-600 dark:text-red-400 bg-gray-100 dark:bg-gray-700 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600">Delete</button>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    @else
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="relative flex-1 min-w-[12rem] max-w-xs">
            <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" style="font-size:16px;">search</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search tasks..." aria-label="Search tasks"
                   class="w-full pl-8 pr-3 py-2 text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:ring-indigo-500 focus:border-indigo-500" />
        </div>
        <select wire:model.live="assignee" aria-label="Assignee" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white py-2">
            <option value="">Anyone</option>
            <option value="me">Assigned to me</option>
            <option value="none">Unassigned</option>
            @foreach($this->members as $member)
                @if($member->id !== auth()->id())
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endif
            @endforeach
        </select>
        <select wire:model.live="priority" aria-label="Priority" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white py-2">
            <option value="">Any priority</option>
            @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                <option value="{{ $p }}">{{ ucfirst($p) }}</option>
            @endforeach
        </select>
        <select wire:model.live="due" aria-label="Due" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white py-2">
            <option value="">Any due date</option>
            @foreach(\App\Livewire\Tasks\TaskBoard::DUE_STATES as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
        </select>
        @if($this->labels->isNotEmpty())
            <select wire:model.live="labelId" aria-label="Label" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white py-2">
                <option value="">Any label</option>
                @foreach($this->labels as $label)
                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                @endforeach
            </select>
        @endif
        @if($this->filtersActive)
            <button type="button" wire:click="clearFilters" class="text-sm text-gray-500 dark:text-gray-400 underline">Clear filters</button>
        @endif
        @can('create', [\App\Models\Task::class, $taskList])
            <button wire:click="$dispatch('open-create-task', { status: 'todo' })"
                    class="ml-auto inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                + Add Task
            </button>
        @endcan
    </div>

    @if(collect($this->tasksByStatus)->every(fn ($c) => $c->isEmpty()))
        <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
            @if($this->filtersActive)
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">search_off</span>
                @if(trim($search) !== '' && $assignee === '' && $priority === '' && $labelId === '' && $due === '')
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No tasks match "{{ $search }}".</p>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No tasks match these filters.</p>
                @endif
            @else
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">task_alt</span>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No tasks yet.@if($canWork) Add your first task to get started.@endif</p>
            @endif
        </div>
    @else
    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach(\App\Livewire\Tasks\TaskBoard::COLUMNS as $status => $label)
            <div class="shrink-0 w-72 bg-gray-100 dark:bg-gray-900 rounded-xl p-3"
                 @if($canWork)
                     x-data
                     x-on:dragover.prevent
                     x-on:drop.prevent="$wire.moveTask($event.dataTransfer.getData('taskId'), '{{ $status }}')"
                 @endif>

                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $label }}</h3>
                    <span class="text-xs bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-full px-2 py-0.5">
                        {{ $this->tasksByStatus[$status]->count() }}
                    </span>
                </div>

                <div class="space-y-2 min-h-8">
                    @forelse($this->tasksByStatus[$status] as $task)
                        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm border border-gray-200 dark:border-gray-700 cursor-pointer group"
                             @if($canWork)
                                 draggable="true"
                                 x-on:dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')"
                             @endif
                             wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                            <p class="text-sm text-gray-900 dark:text-white font-medium mb-2">
                                {{ $task->title }}
                                @if($task->isRecurring())
                                    <span title="Repeats {{ $task->recurrence_type === 'custom_days' ? 'every '.$task->recurrence_interval.' days' : $task->recurrence_type }}">🔁</span>
                                @endif
                            </p>
                            <div class="flex items-center justify-between">
                                <span class="text-xs px-1.5 py-0.5 rounded
                                    {{ match($task->priority) {
                                        'urgent' => 'bg-red-100 text-red-700',
                                        'high'   => 'bg-orange-100 text-orange-700',
                                        'medium' => 'bg-yellow-100 text-yellow-700',
                                        'low'    => 'bg-gray-100 text-gray-600',
                                        default  => 'bg-gray-100 text-gray-600',
                                    } }}">
                                    {{ ucfirst($task->priority) }}
                                </span>
                                @if($task->due_date)
                                    <span class="text-xs {{ $task->due_date->isPast() && $task->status !== 'done' ? 'text-red-500 font-semibold' : 'text-gray-400 dark:text-gray-500' }}">{{ $task->due_date->format('M j') }}</span>
                                @endif
                            </div>
                            @if($task->labels->isNotEmpty())
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    @foreach($task->labels as $label)
                                        <x-label-chip :label="$label" />
                                    @endforeach
                                </div>
                            @endif
                            <div class="flex items-center justify-between mt-1.5">
                                @if($task->subtasks->isNotEmpty())
                                    <p class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $task->subtasks->where('status', 'done')->count() }}/{{ $task->subtasks->count() }} subtasks
                                    </p>
                                @else
                                    <span></span>
                                @endif
                                @if($task->assignee)
                                    <span title="{{ $task->assignee->name }}" class="flex items-center justify-center w-5 h-5 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 text-[10px] font-semibold">
                                        {{ strtoupper(substr($task->assignee->name, 0, 1)) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 dark:text-gray-600 text-center py-4">No tasks here.</p>
                    @endforelse
                </div>

                @if($canWork)
                    <button wire:click="$dispatch('open-create-task', { status: '{{ $status }}' })"
                            class="mt-2 w-full text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 py-1 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-800 transition-colors">
                        + Add task
                    </button>
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
