<div>
    <div class="tabs" style="margin-bottom:12px">
        <a href="{{ route('tasks.due') }}" class="tab {{ $view === 'today' ? 'active' : '' }}">Due now</a>
        <a href="{{ route('tasks.upcoming') }}" class="tab {{ $view === 'upcoming' ? 'active' : '' }}">Upcoming</a>
    </div>

    @error('status')
        <p role="alert" class="alert">{{ $message }}</p>
    @enderror

    @if($view === 'today')
        @if($this->todayTasks->isEmpty())
            <div class="empty">Nothing due today. Nice.</div>
        @else
            <div class="rows">
                @foreach($this->todayTasks as $task)
                    <div class="row" wire:key="today-{{ $task->id }}">
                        @can('move', $task)
                            <button class="tick" wire:click="completeTask({{ $task->id }})" role="checkbox" aria-checked="false" title="Mark done" aria-label="Mark {{ $task->title }} done"></button>
                        @endcan
                        <button type="button" class="row-main" style="border:0;background:none;padding:0;text-align:left" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                            <b>{{ $task->title }}</b>
                            <span>{{ $task->taskList->name }}</span>
                        </button>
                        <span class="row-end">
                            <span @class(['num', 'overdue' => $task->due_date->isPast() && ! $task->due_date->isToday()])>{{ $task->due_date->isToday() ? 'Today' : $task->due_date->diffForHumans() }}</span>
                            <span class="chip">{{ ucfirst($task->priority) }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        @if($this->upcomingTasksByDate->isEmpty())
            <div class="empty">Nothing due in the next 7 days.</div>
        @else
            @foreach($this->upcomingTasksByDate as $date => $tasks)
                <h3 class="group-head">{{ \Illuminate\Support\Carbon::parse($date)->isTomorrow() ? 'Tomorrow' : \Illuminate\Support\Carbon::parse($date)->format('l, M j') }}</h3>
                <div class="rows">
                    @foreach($tasks as $task)
                        <div class="row" wire:key="upcoming-{{ $task->id }}">
                            @can('move', $task)
                                <button class="tick" wire:click="completeTask({{ $task->id }})" role="checkbox" aria-checked="false" title="Mark done" aria-label="Mark {{ $task->title }} done"></button>
                            @endcan
                            <button type="button" class="row-main" style="border:0;background:none;padding:0;text-align:left" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                                <b>{{ $task->title }}</b>
                                <span>{{ $task->taskList->name }}</span>
                            </button>
                            <span class="row-end"><span class="chip">{{ ucfirst($task->priority) }}</span></span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    @endif
</div>
