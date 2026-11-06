@if($this->data['boards'] === [])
    <p class="widget-empty">No boards yet.</p>
@else
    <div class="grid-scroll" style="border:0">
        <table class="plain-table">
            <thead><tr><th scope="col">Board</th><th scope="col">Open</th><th scope="col">Overdue</th><th scope="col">Blocked</th><th scope="col">Finished in 30 days</th></tr></thead>
            <tbody>
                @foreach($this->data['boards'] as $board)
                    <tr wire:key="b-{{ $board['id'] }}">
                        <th scope="row"><a class="link" style="text-decoration:none" href="{{ route('task-lists.show', $board['id']) }}">{{ $board['name'] }}</a></th>
                        <td class="num">{{ $board['open'] }}</td>
                        <td @class(['num', 'overdue' => $board['overdue'] > 0])>{{ $board['overdue'] }}</td>
                        <td class="num">{{ $board['blocked'] }}</td>
                        <td class="num">{{ $board['finished'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
