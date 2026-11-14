<div class="d-week d-in" style="--i:5" role="group" aria-label="This week, my open tasks by due day">
    @foreach($this->data['week'] as $slot)
        @php $due = $slot['tasks']->count(); @endphp
        <a @class(['today' => $slot['today'], 'past' => $slot['past']]) href="{{ route('tasks.today') }}" style="--r:{{ $loop->index }}"
           aria-label="{{ $slot['name'] }} {{ $slot['number'] }}, {{ $due }} due{{ $slot['today'] ? ', today' : '' }}{{ $slot['past'] && $due > 0 ? ', overdue' : '' }}">
            <span class="nm"><span>{{ $slot['name'] }} <b>{{ $slot['number'] }}</b></span>@if($due > 0)<span class="cnt" aria-hidden="true">{{ $due }}</span>@endif</span>
            <span class="edges" aria-hidden="true">
                @foreach($slot['tasks']->take(5) as $task)<i></i>@endforeach
            </span>
        </a>
    @endforeach
</div>
