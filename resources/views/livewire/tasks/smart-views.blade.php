<div>
    <div class="flex items-center gap-2 mb-4">
        <a href="{{ route('tasks.today') }}"
           class="px-3 py-1.5 text-sm font-medium rounded-lg {{ $view === 'today' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">
            Today
        </a>
        <a href="{{ route('tasks.upcoming') }}"
           class="px-3 py-1.5 text-sm font-medium rounded-lg {{ $view === 'upcoming' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">
            Upcoming
        </a>
    </div>

    @error('status')
        <p role="alert" class="mb-3 px-3 py-2 text-sm text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-300 rounded-md">{{ $message }}</p>
    @enderror

    @if($view === 'today')
        @if($this->todayTasks->isEmpty())
            <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">task_alt</span>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Nothing due today. Nice.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach($this->todayTasks as $task)
                    <div class="flex items-center gap-3 bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm border border-gray-200 dark:border-gray-700">
                        @can('move', $task)
                            <button wire:click="completeTask({{ $task->id }})"
                                    class="shrink-0 w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 hover:border-indigo-500"
                                    title="Mark done" aria-label="Mark {{ $task->title }} done"></button>
                        @endcan
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-900 dark:text-white font-medium truncate">{{ $task->title }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $task->taskList->name }}</p>
                        </div>
                        <span class="text-xs {{ $task->due_date->isPast() ? 'text-red-500 font-semibold' : 'text-gray-400' }}">
                            {{ $task->due_date->isToday() ? 'Today' : $task->due_date->diffForHumans() }}
                        </span>
                        <span class="text-xs px-1.5 py-0.5 rounded
                            {{ match($task->priority) {
                                'urgent' => 'bg-red-100 text-red-700',
                                'high'   => 'bg-orange-100 text-orange-700',
                                'medium' => 'bg-yellow-100 text-yellow-700',
                                default  => 'bg-gray-100 text-gray-600',
                            } }}">
                            {{ ucfirst($task->priority) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        @if($this->upcomingTasksByDate->isEmpty())
            <div class="text-center py-16 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
                <span class="material-symbols-rounded text-gray-400 dark:text-gray-600" style="font-size:32px;">event_upcoming</span>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Nothing due in the next 7 days.</p>
            </div>
        @else
            <div class="space-y-5">
                @foreach($this->upcomingTasksByDate as $date => $tasks)
                    <div>
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
                            {{ \Illuminate\Support\Carbon::parse($date)->isTomorrow() ? 'Tomorrow' : \Illuminate\Support\Carbon::parse($date)->format('l, M j') }}
                        </h3>
                        <div class="space-y-2">
                            @foreach($tasks as $task)
                                <div class="flex items-center gap-3 bg-white dark:bg-gray-800 rounded-lg p-3 shadow-sm border border-gray-200 dark:border-gray-700">
                                    @can('move', $task)
                                        <button wire:click="completeTask({{ $task->id }})"
                                                class="shrink-0 w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 hover:border-indigo-500"
                                                title="Mark done" aria-label="Mark {{ $task->title }} done"></button>
                                    @endcan
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-white font-medium truncate">{{ $task->title }}</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $task->taskList->name }}</p>
                                    </div>
                                    <span class="text-xs px-1.5 py-0.5 rounded
                                        {{ match($task->priority) {
                                            'urgent' => 'bg-red-100 text-red-700',
                                            'high'   => 'bg-orange-100 text-orange-700',
                                            'medium' => 'bg-yellow-100 text-yellow-700',
                                            default  => 'bg-gray-100 text-gray-600',
                                        } }}">
                                        {{ ucfirst($task->priority) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
