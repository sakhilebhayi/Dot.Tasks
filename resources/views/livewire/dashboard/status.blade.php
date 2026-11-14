@php
    $counts = $this->data['status'];
    $total = array_sum($counts);
    $names = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'Review', 'done' => 'Done'];
@endphp
@if($total === 0)
    <p class="d-empty">No tasks yet.</p>
@else
    <div class="d-stack" role="img" aria-label="{{ collect($names)->map(fn ($name, $key) => $name.' '.$counts[$key])->join(', ') }}">
        @foreach($counts as $key => $count)
            @if($count > 0)<i class="seg s-{{ $key }}" style="flex-grow:{{ $count }}" title="{{ $names[$key] }}: {{ $count }}"></i>@endif
        @endforeach
    </div>
    <div class="d-legend">
        @foreach($counts as $key => $count)
            <span><i class="key s-{{ $key }}"></i>{{ $names[$key] }} <b class="num">{{ $count }}</b></span>
        @endforeach
    </div>
@endif
