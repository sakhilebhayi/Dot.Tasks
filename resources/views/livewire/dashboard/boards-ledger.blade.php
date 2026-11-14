@php $team = auth()->user()->currentTeam; @endphp
<section class="d-group boards" aria-labelledby="g-boards" style="--g:3">
    <div class="d-head">
        <h2 id="g-boards">Boards</h2>
        <span class="count num">{{ $this->boardRows->count() }}</span>
        @can('create', \App\Models\TaskList::class)
            <a class="more" href="{{ route('task-lists.create') }}">New board</a>
        @endcan
    </div>
    @if($this->boardRows->isEmpty())
        <div class="d-empty" data-boards-empty>
            <p>No boards yet.</p>
            @can('create', \App\Models\TaskList::class)
                <p>A board is a rack the whole team works from. Your Inbox works without one, so there is no hurry.</p>
            @else
                <p>An editor or admin at {{ $team->name }} makes the boards. They will appear here.</p>
            @endcan
        </div>
    @else
        <div class="d-rows">
            @foreach($this->boardRows as $list)
                <a class="d-row" wire:key="board-{{ $list->id }}" href="{{ route('task-lists.show', $list) }}" style="--r:{{ min($loop->index, 7) }}">
                    <span class="d-main"><b><span class="dot" style="background: {{ $list->color }}"></span>{{ $list->name }}</b></span>
                    <span class="d-cell"><b class="num">{{ $list->open_count }}</b>&nbsp;open</span>
                </a>
            @endforeach
        </div>
    @endif
    <livewire:tasks.archived-boards />
</section>
