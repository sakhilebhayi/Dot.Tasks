<x-dot.search wire="query" name="find" label="Find a task" placeholder="Find a task" escape-clears
    :form-attributes="['wire:submit.prevent' => 'go', 'data-find-pill' => '']">
    <x-slot:results>
        @if(! $this->ready())
            <div class="dsr-quick">
                <div class="dsr-head">Go to</div>
                <div class="dsr-chips">
                    @foreach ([['tasks.today', 'My Day'], ['tasks.inbox', 'Inbox'], ['tasks.mine', 'My tasks'], ['tasks.upcoming', 'Upcoming'], ['dashboard', 'Overview']] as [$route, $label])
                        <a class="dsr-chip" href="{{ route($route) }}">{{ $label }}</a>
                    @endforeach
                </div>
                @if($this->allBoards->isNotEmpty())
                    <div class="dsr-head">Boards</div>
                    @foreach($this->allBoards as $board)
                        <a class="dsr-item" role="option" data-find-result href="{{ route('task-lists.show', $board) }}" wire:key="hb-{{ $board->id }}"><span class="dsr-label">{{ $board->name }}</span></a>
                    @endforeach
                @endif
                <p class="dsr-foot">Type two letters to find a task, or ask: overdue maintenance for James. Ctrl K opens the full find window.</p>
            </div>
        @else
            <div class="dsr-quick">
                @if($this->reading['understood'] !== [])
                    <div class="dsr-chips" aria-label="What was understood">
                        @foreach($this->reading['understood'] as $part)<span class="dsr-chip">{{ $part }}</span>@endforeach
                        @if($this->reading['words'] !== '')<span class="dsr-chip">Words: {{ $this->reading['words'] }}</span>@endif
                    </div>
                @endif
                @if($this->tasks->isNotEmpty())
                    <div class="dsr-head">Tasks</div>
                    @foreach($this->tasks as $task)
                        <button type="button" class="dsr-item" role="option" data-find-result wire:key="ht-{{ $task->id }}"
                                wire:click="$dispatch('open-task', { taskId: {{ $task->id }} }); picked()">
                            <span class="dsr-label">{{ $task->title }}</span>
                            <span class="dsr-tag">{{ $task->taskList->is_inbox ?? false ? 'Inbox' : $task->taskList->name }}</span>
                        </button>
                    @endforeach
                @endif
                @if($this->boards->isNotEmpty())
                    <div class="dsr-head">Boards</div>
                    @foreach($this->boards as $board)
                        <a class="dsr-item" role="option" data-find-result href="{{ route('task-lists.show', $board) }}" wire:key="hbm-{{ $board->id }}"><span class="dsr-label">{{ $board->name }}</span></a>
                    @endforeach
                @endif
                @if($this->tasks->isEmpty() && $this->boards->isEmpty())
                    <p class="dsr-foot" role="status">Nothing matches &ldquo;{{ trim($query) }}&rdquo;. Try fewer words.</p>
                @endif
            </div>
        @endif
    </x-slot:results>
</x-dot.search>
