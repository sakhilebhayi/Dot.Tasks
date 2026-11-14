<section class="land" aria-labelledby="land-title">
    <div class="land-top">
        {{-- The original logo files, as drawn. Only the size and the space around them are set here. --}}
        <img class="land-logo land-logo-day" src="{{ asset('images/logo.png') }}" alt="Dot.Tasks" width="1181" height="886">
        <img class="land-logo land-logo-night" src="{{ asset('images/logo-light.png') }}" alt="Dot.Tasks" width="1181" height="886">
        <nav class="land-back" aria-label="Dot ecosystem">
            <a href="{{ $infodotHome }}">InfoDot</a>
            <a href="{{ $infodotPlatforms }}">Platforms</a>
        </nav>
    </div>

    <div class="land-body">
        <div class="land-say">
            <h1 id="land-title">Every job on a card.</h1>
            <p class="land-lead">Welcome, {{ $firstName }}. Dot.Tasks is where {{ $company }} writes work down, gives it to someone and sees it finished.</p>
            {{-- With scripts the sentence box opens here; without them the link still goes to the Inbox, where a task can be written. --}}
            <a class="btn primary land-go" href="{{ route('tasks.inbox') }}" data-primary-action data-open-quick-add><x-icon name="plus" /> New task</a>
            <p class="land-note">
                @if($sharesBoards)
                    It starts in your Inbox, which only you see. Choose a board as you write it to share it with the team.
                @else
                    It starts in your Inbox, which only you see. You can read every board at {{ $company }}; an editor or admin puts work on them.
                @endif
            </p>
        </div>

        <div class="land-rack">
            <h2 class="slot-head" id="land-mine">On your name @if($this->mine['total'] > 0)<span class="count" data-land-count>{{ $this->mine['total'] }}</span>@endif</h2>
            @forelse($this->mine['cards'] as $task)
                <a class="card" wire:key="land-{{ $task->id }}" href="{{ route('task-lists.show', $task->taskList) }}" data-land-card
                   wire:click.prevent="$dispatch('open-task', { taskId: {{ $task->id }} })">
                    @if(in_array($task->priority, ['urgent', 'high', 'low'], true))
                        <span class="notch {{ $task->priority }}" title="{{ ucfirst($task->priority) }} priority"></span>
                    @endif
                    <p class="card-title">{{ $task->title }}</p>
                    @if($task->isBlocked())
                        <p class="blocked-line">Blocked</p>
                    @endif
                    <div class="card-meta">
                        <span class="sr">{{ ucfirst($task->priority) }} priority</span>
                        <x-task-due :task="$task" />
                        <span class="land-board">{{ $task->taskList->is_inbox ? 'Inbox' : $task->taskList->name }}</span>
                    </div>
                </a>
            @empty
                <div class="land-open" data-land-empty>
                    <p><b>Nothing is on your name.</b></p>
                    <p>Write a task and its card seats here. So does anything a teammate gives you.</p>
                </div>
            @endforelse
            @if($this->mine['total'] > 0)
                <a class="add-line" href="{{ route('tasks.mine') }}">{{ $this->mine['total'] > $this->mine['cards']->count() ? 'See all '.$this->mine['total'].' in My tasks' : 'Open My tasks' }}</a>
            @endif
        </div>
    </div>
</section>
