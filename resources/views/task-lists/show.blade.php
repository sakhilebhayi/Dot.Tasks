<x-app-layout :title="$taskList->name">
    @php
        $keep = request()->only(['q', 'priority', 'label', 'due', 'assignee']);
        $views = ['board' => 'Board', 'list' => 'List', 'calendar' => 'Calendar', 'timeline' => 'Timeline'];
    @endphp
    <div class="page">
        <div class="page-head">
            <div style="display:flex;align-items:center;gap:10px">
                <span class="nav-dot" style="background: {{ $taskList->color }};margin:0;width:12px;height:12px"></span>
                <h1>{{ $taskList->is_inbox ? 'Inbox' : $taskList->name }}</h1>
            </div>
            @if($taskList->is_inbox)<p>Only you can see this.</p>@endif
        </div>
        <nav class="tabs view-tabs" aria-label="Ways to see this board">
            @foreach($views as $key => $name)
                <a class="tab" href="{{ route('task-lists.show', [$taskList->id] + ($key === 'board' ? [] : ['view' => $key]) + $keep) }}" @if($view === $key) aria-current="page" @endif>{{ $name }}</a>
            @endforeach
            <a class="tab" href="{{ route('task-lists.show', [$taskList->id, 'show' => 'archived']) }}" @if($view === 'archived') aria-current="page" @endif>Archived</a>
            @can('archive', $taskList)
                <span style="margin-left:auto"><livewire:tasks.archive-board :task-list="$taskList" /></span>
            @endcan
        </nav>
        @if($view === 'list')
            <livewire:tasks.task-table :task-list="$taskList" />
        @elseif($view === 'calendar')
            <livewire:tasks.task-calendar :task-list="$taskList" />
        @elseif($view === 'timeline')
            <livewire:tasks.task-timeline :task-list="$taskList" />
        @else
            <livewire:tasks.task-board :task-list="$taskList" />
        @endif
    </div>
</x-app-layout>
