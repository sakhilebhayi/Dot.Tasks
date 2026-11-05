<x-app-layout :title="$taskList->name">
    <div class="page">
        <div class="page-head">
            <div style="display:flex;align-items:center;gap:10px">
                <span class="nav-dot" style="background: {{ $taskList->color }};margin:0;width:12px;height:12px"></span>
                <h1>{{ $taskList->name }}</h1>
            </div>
        </div>
        <livewire:tasks.task-board :task-list="$taskList" />
    </div>
</x-app-layout>
