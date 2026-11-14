<x-app-layout title="Overview">
    @php
        $team = Auth::user()->currentTeam;
        // A company that has written nothing down yet: eight empty panels would say less than one sentence.
        $nothingYet = $taskCounts['total'] === 0 && $lists->isEmpty();
    @endphp
    <div class="page">
        <livewire:landing />

        @unless($nothingYet)
            <div class="page-head">
                <div>
                    <h2 class="land-next">Overview</h2>
                    <p>{{ $taskCounts['overdue'] > 0 ? $taskCounts['overdue'].' overdue at '.$team->name : 'Nothing overdue at '.$team->name }}</p>
                </div>
            </div>

            <livewire:dashboard />
        @endunless

        <div class="page-head land-boards">
            <h2 class="land-next">Boards</h2>
            @can('create', \App\Models\TaskList::class)
                <a class="btn" href="{{ route('task-lists.create') }}"><x-icon name="plus" /> New board</a>
            @endcan
        </div>
        @if($lists->isEmpty())
            <div class="empty" data-boards-empty>
                <p>No boards yet.</p>
                @can('create', \App\Models\TaskList::class)
                    <p>A board is a rack the whole team works from. Your Inbox works without one, so there is no hurry.</p>
                @else
                    <p>An editor or admin at {{ $team->name }} makes the boards. They will appear here.</p>
                @endcan
            </div>
        @else
            <div class="rows">
                @foreach($lists as $list)
                    <a class="row" href="{{ route('task-lists.show', $list) }}">
                        <span class="nav-dot" style="background: {{ $list->color }}"></span>
                        <span class="row-main">
                            <b>{{ $list->name }}</b>
                            @if($list->description)<span>{{ $list->description }}</span>@endif
                        </span>
                        <span class="row-end num">{{ $list->tasks_count }} {{ Str::plural('task', $list->tasks_count) }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <livewire:tasks.archived-boards />
    </div>
</x-app-layout>
