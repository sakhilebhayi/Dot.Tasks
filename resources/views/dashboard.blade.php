<x-app-layout title="Overview">
    @php
        $team = Auth::user()->currentTeam;
        // A company that has written nothing down yet: panels of empty groups would say less than one sentence.
        $nothingYet = $taskCounts['total'] === 0 && $lists->isEmpty() && ! $anythingWritten;
    @endphp
    <div class="page page-ov">
        <livewire:landing />

        @if($nothingYet)
            {{-- The rack is empty: no figures, one honest sentence about boards, and the same ways to make one. --}}
            <div class="page-head land-boards">
                <h2 class="land-next">Boards</h2>
                @can('create', \App\Models\TaskList::class)
                    <a class="btn" href="{{ route('task-lists.create') }}"><x-icon name="plus" /> New board</a>
                @endcan
            </div>
            <div class="empty" data-boards-empty>
                <p>No boards yet.</p>
                @can('create', \App\Models\TaskList::class)
                    <p>A board is a rack the whole team works from. Your Inbox works without one, so there is no hurry.</p>
                @else
                    <p>An editor or admin at {{ $team->name }} makes the boards. They will appear here.</p>
                @endcan
            </div>
            <livewire:tasks.archived-boards />
        @else
            <livewire:dashboard />
        @endif
    </div>
</x-app-layout>
