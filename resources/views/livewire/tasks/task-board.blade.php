<div>
    @php $canWork = auth()->user()->can('create', [\App\Models\Task::class, $taskList]); @endphp
    <div class="flex items-center gap-3 mb-4">
        <div class="relative flex-1 max-w-xs">
            <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" style="font-size:16px;">search</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search tasks..."
                   class="w-full pl-8 pr-3 py-2 text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:ring-indigo-500 focus:border-indigo-500" />
        </div>
        @can('create', [\App\Models\Task::class, $taskList])
            <button wire:click="$dispatch('open-create-task', { status: 'todo' })"
                    class="ml-auto inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                + Add Task
            </button>
        @endcan
    </div>

    @if(collect($this->tasksByStatus)->every(fn ($c) => $c->isEmpty()))
        <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
            @if($search !== '')
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">search_off</span>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No tasks match "{{ $search }}".</p>
            @else
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">task_alt</span>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No tasks yet. Add your first task to get started.</p>
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
