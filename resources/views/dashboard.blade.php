<x-app-layout title="Overview">
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Overview</h1>
                <p>{{ $taskCounts['overdue'] > 0 ? $taskCounts['overdue'].' overdue on '.Auth::user()->currentTeam->name : 'Nothing overdue on '.Auth::user()->currentTeam->name }}</p>
            </div>
            @can('create', \App\Models\TaskList::class)
                <a class="btn primary" href="{{ route('task-lists.create') }}"><x-icon name="plus" /> New board</a>
            @endcan
        </div>

        <div class="figures">
            <div class="figure"><span>Boards</span><b>{{ $lists->count() }}</b></div>
            <div class="figure"><span>Tasks</span><b>{{ $taskCounts['total'] }}</b></div>
            <div class="figure"><span>In progress</span><b>{{ $taskCounts['in_progress'] }}</b></div>
            <div class="figure"><span>Due today</span><b>{{ $taskCounts['due_today'] }}</b></div>
            <div class="figure"><span>Overdue</span><b @class(['overdue' => $taskCounts['overdue'] > 0])>{{ $taskCounts['overdue'] }}</b></div>
            <div class="figure"><span>Completed this week</span><b>{{ $taskCounts['completed_week'] }}</b></div>
            <div class="figure"><span>Completed</span><b>{{ $taskCounts['done'] }}</b></div>
        </div>

        <h2 class="section-title">Boards</h2>
        @if($lists->isEmpty())
            <div class="empty">
                <p>No task lists yet</p>
                @can('create', \App\Models\TaskList::class)
                    <p>Create your first list to start organising tasks.</p>
                    <p style="margin-top:10px"><a class="btn" href="{{ route('task-lists.create') }}">Create your first list</a></p>
                @else
                    <p>An editor or admin on this team creates the boards. They will appear here.</p>
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
