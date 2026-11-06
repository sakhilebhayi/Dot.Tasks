<div>
    @error('status')<p role="alert" class="alert">{{ $message }}</p>@enderror

    <div class="week-rack" aria-label="This week" role="group">
        @foreach($this->week as $slot)
            <div class="week-drop" data-date="{{ $slot['date'] }}" wire:key="week-{{ $slot['date'] }}" x-data="rackDay">
                <button type="button" @class(['week-slot', 'today' => $slot['today'], 'past' => $slot['past']]) aria-pressed="{{ $this->day->toDateString() === $slot['date'] ? 'true' : 'false' }}"
                        aria-label="{{ $slot['name'] }} {{ $slot['number'] }}, {{ $slot['tasks']->count() }} due" wire:click="pick('{{ $slot['date'] }}')">
                    <span class="week-name">{{ $slot['name'] }} <b>{{ $slot['number'] }}</b></span>
                    <span class="edges" aria-hidden="true">
                        @foreach($slot['tasks'] as $task)
                            <i title="{{ $task->title }}"></i>
                        @endforeach
                    </span>
                </button>
            </div>
        @endforeach
    </div>

    <div class="day-wrap">
        <section aria-labelledby="plan-heading">
            <div class="day-head">
                <h2 id="plan-heading">Today's plan <span class="count">{{ $this->plan->where('status', 'done')->count() }}/{{ $this->plan->count() }}</span></h2>
                @if($this->leftOver > 0)
                    <button type="button" class="btn small" wire:click="carryOver">Bring over {{ $this->leftOver }} left unfinished</button>
                @endif
            </div>
            <div class="plan" data-plan x-data="dayPlan" x-on:keydown="keys($event)">
                @foreach($this->plan as $task)
                    @include('livewire.tasks.partials.day-card', ['planned' => true])
                @endforeach
            </div>
            @if($this->plan->isEmpty())
                <p class="plan-empty">Drag work here, in the order you mean to do it.</p>
            @endif
        </section>

        <div class="day-sources">
            @if($this->focus !== [])
                <section aria-labelledby="focus-heading">
                    <h3 class="group-head" style="padding-top:0" id="focus-heading">Worth doing first <span class="count" title="A suggestion, worked out from the dates, priority and links on these tasks">suggested</span></h3>
                    <div class="source" data-source x-data="dayPlan">
                        @foreach($this->focus as $row)
                            @php $task = $row['task']; @endphp
                            <div class="day-card" wire:key="focus-{{ $task->id }}" data-task="{{ $task->id }}" data-title="{{ $task->title }}" data-date="{{ $task->due_date?->toDateString() }}">
                                <button type="button" class="day-title" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">
                                    <b>{{ $task->title }}</b>
                                    <span class="focus-why">{{ implode('. ', $row['reasons']) }}.</span>
                                </button>
                                <button type="button" class="icon-btn" aria-label="Add {{ $task->title }} to the plan" wire:click="planIt({{ $task->id }})"><x-icon name="plus" /></button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
            @foreach([
                ['To approve', $this->toApprove, 'approve'],
                ['Overdue', $this->overdue, 'overdue'],
                [$this->day->isSameDay(\App\Support\Today::date()) ? 'Due today' : 'Due '.$this->day->format('l'), $this->dueOnDay, 'day'],
                ['Needs attention', $this->attention, 'attention'],
                ['Coming up', $this->upcoming, 'upcoming'],
            ] as [$name, $tasks, $key])
                <section aria-label="{{ $name }}">
                    <h3 class="group-head" style="padding-top:0">{{ $name }} <span class="count">{{ $tasks->count() }}</span></h3>
                    <div class="source" data-source x-data="dayPlan">
                        @foreach($tasks as $task)
                            @include('livewire.tasks.partials.day-card', ['planned' => false, 'key' => $key])
                        @endforeach
                    </div>
                    @if($tasks->isEmpty())<p class="source-empty">Nothing here.</p>@endif
                </section>
            @endforeach
            @if($this->finished->isNotEmpty())
                <section aria-label="Finished recently">
                    <h3 class="group-head" style="padding-top:0">Finished recently</h3>
                    @foreach($this->finished as $task)
                        <div class="day-card is-done" wire:key="finished-{{ $task->id }}">
                            <button type="button" class="tick" role="checkbox" aria-checked="true" aria-label="Reopen {{ $task->title }}" wire:click="toggleDone({{ $task->id }})"></button>
                            <button type="button" class="day-title" wire:click="$dispatch('open-task', { taskId: {{ $task->id }} })">{{ $task->title }}</button>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>
    </div>
    <div x-data="pulse" hidden></div>
</div>
