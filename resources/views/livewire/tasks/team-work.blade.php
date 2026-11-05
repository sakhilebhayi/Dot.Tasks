<div>
    <div class="filters">
        <select class="select bare" wire:model.live="board" aria-label="Board">
            <option value="">Every board</option>
            @foreach($this->boards as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
        <select class="select bare" wire:model.live="show" aria-label="Show">
            @foreach(\App\Livewire\Tasks\TeamWork::SHOWS as $value => $name)
                <option value="{{ $value }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    @error('status')<p role="alert" class="alert">{{ $message }}</p>@enderror

    <div class="rack" data-rack tabindex="0" role="region" aria-label="Work by person">
        @foreach($this->people as $person)
            <div class="slot" wire:key="person-{{ $person['key'] }}">
                <h3 class="slot-head">{{ $person['name'] }}</h3>
                <p class="slot-sums">
                    <span><span class="num">{{ $person['open'] }}</span> open</span>
                    @if($person['overdue'])<span class="overdue"><span class="num">{{ $person['overdue'] }}</span> overdue</span>@endif
                    @if($person['blocked'])<span class="wait"><span class="num">{{ $person['blocked'] }}</span> blocked</span>@endif
                    @if($person['minutes'])<span><span class="num">{{ round($person['minutes'] / 60, 1) }}</span>h estimated</span>@endif
                </p>
                <div class="slot-body" data-list data-person="{{ $person['key'] }}" data-name="{{ $person['name'] }}" wire:key="held-{{ $person['key'] }}" x-data="personList" x-on:keydown="keys($event)">
                    @foreach($person['tasks'] as $task)
                        <div class="card" wire:key="held-card-{{ $task->id }}" tabindex="0" role="button" data-task="{{ $task->id }}" data-title="{{ $task->title }}" data-person="{{ $person['key'] }}"
                             aria-describedby="person-help" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })" x-on:keydown.enter="$dispatch('open-task', { taskId: {{ $task->id }} })">
                            @if(in_array($task->priority, ['urgent', 'high', 'low'], true))
                                <span class="notch {{ $task->priority }}" title="{{ ucfirst($task->priority) }} priority"></span>
                            @endif
                            <p class="card-title">{{ $task->title }}</p>
                            @if($task->isBlocked())
                                <p class="blocked-line">Blocked</p>
                            @endif
                            <div class="card-meta">
                                <span>{{ $task->taskList->name }}</span>
                                <x-task-due :task="$task" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <div x-data="pulse" hidden></div>
</div>
