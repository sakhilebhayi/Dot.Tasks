<div>
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="relative flex-1 min-w-[12rem] max-w-xs">
            <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" style="font-size:16px;">search</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search my tasks..." aria-label="Search my tasks"
                   class="w-full pl-8 pr-3 py-2 text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white focus:ring-indigo-500 focus:border-indigo-500" />
        </div>
        <select wire:model.live="priority" aria-label="Priority" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white py-2">
            <option value="">Any priority</option>
            @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                <option value="{{ $p }}">{{ ucfirst($p) }}</option>
            @endforeach
        </select>
        <select wire:model.live="due" aria-label="Due" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white py-2">
            <option value="">Any due date</option>
            @foreach(\App\Livewire\Tasks\MyTasks::DUE_STATES as $value => $text)
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
        <label class="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400">
            <input type="checkbox" wire:model.live="showDone" class="rounded border-gray-300 dark:border-gray-600"> Include done
        </label>
        @if($this->filtersActive)
            <button type="button" wire:click="clearFilters" class="text-sm text-gray-500 dark:text-gray-400 underline">Clear filters</button>
        @endif
    </div>

    @if($this->tasks->isEmpty())
        <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
            <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">task_alt</span>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ $this->filtersActive ? 'None of your tasks match these filters.' : 'Nothing is assigned to you.' }}
            </p>
        </div>
    @else
        <div class="space-y-2">
            @foreach($this->tasks as $task)
                <div wire:key="mine-{{ $task->id }}" class="flex items-center gap-3 bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm border border-gray-200 dark:border-gray-700">
                    @can('move', $task)
                        @if($task->status === 'done')
                            <span class="shrink-0 w-5 h-5 rounded-full bg-green-500" title="Done"></span>
                        @else
                            <button wire:click="completeTask({{ $task->id }})"
                                    class="shrink-0 w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 hover:border-indigo-500"
                                    title="Mark done" aria-label="Mark {{ $task->title }} done"></button>
                        @endif
                    @endcan
                    <button type="button" class="flex-1 min-w-0 text-left" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                        <span class="block text-sm text-gray-900 dark:text-white font-medium truncate {{ $task->status === 'done' ? 'line-through opacity-60' : '' }}">{{ $task->title }}</span>
                        <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $task->taskList->name }}</span>
                    </button>
                    @foreach($task->labels as $label)
                        <x-label-chip :label="$label" class="hidden sm:inline-flex" />
                    @endforeach
                    @if($task->due_date)
                        <span class="text-xs {{ $task->status !== 'done' && $task->due_date->isPast() && ! $task->due_date->isToday() ? 'text-red-500 font-semibold' : 'text-gray-400' }}">
                            {{ $task->due_date->isToday() ? 'Today' : $task->due_date->format('M j') }}
                        </span>
                    @endif
                    <span class="text-xs px-1.5 py-0.5 rounded
                        {{ match($task->priority) {
                            'urgent' => 'bg-red-100 text-red-700',
                            'high' => 'bg-orange-100 text-orange-700',
                            'medium' => 'bg-yellow-100 text-yellow-700',
                            default => 'bg-gray-100 text-gray-600',
                        } }}">
                        {{ ucfirst($task->priority) }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>
