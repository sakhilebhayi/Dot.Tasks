@if($boards === [])
    <p class="d-empty">No boards yet.</p>
@else
    <table class="d-table" aria-label="{{ $title }}">
        <thead><tr><th scope="col">Board</th><th scope="col">Open</th><th scope="col">Overdue</th><th scope="col">Blocked</th><th scope="col" class="opt">Nobody on it</th><th scope="col" class="opt">Finished, 30 days</th></tr></thead>
        <tbody>
            @foreach($boards as $board)
                <tr wire:key="b-{{ $view }}-{{ $board['id'] }}" style="--r:{{ min($loop->index, 7) }}">
                    <td><a href="{{ route('task-lists.show', $board['id']) }}"><span class="dot" style="background: {{ $board['color'] }}"></span>{{ $board['name'] }}</a></td>
                    <td class="num">{{ $board['open'] }}</td>
                    <td @class(['num', 'hot' => $board['overdue'] > 0, 'z' => $board['overdue'] === 0])>{{ $board['overdue'] }}</td>
                    <td @class(['num', 'amber' => $board['blocked'] > 0, 'z' => $board['blocked'] === 0])>{{ $board['blocked'] }}</td>
                    <td class="num opt">{{ $board['unassigned'] }}</td>
                    <td class="num opt">{{ $board['finished'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
