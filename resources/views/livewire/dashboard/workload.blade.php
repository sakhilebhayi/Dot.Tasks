@php
    $rows = $this->data['workload'];
    $most = max(1, collect($rows)->max('open'));
@endphp
@if($rows === [])
    <p class="d-empty">No open work.</p>
@else
    <div class="d-hbars">
        @foreach($rows as $row)
            <div class="d-hbar" wire:key="load-{{ $loop->index }}" style="--r:{{ min($loop->index, 7) }}">
                <span class="nm" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                <span class="track" tabindex="0" role="img" title="{{ $row['open'] }} open, {{ $row['overdue'] }} overdue" aria-label="{{ $row['name'] }}: {{ $row['open'] }} open, {{ $row['overdue'] }} overdue">
                    @if($row['overdue'] > 0)<i class="seg late" style="width:{{ 100 * $row['overdue'] / $most }}%"></i>@endif
                    @if($row['open'] > $row['overdue'])<i class="seg" style="width:{{ 100 * ($row['open'] - $row['overdue']) / $most }}%"></i>@endif
                </span>
                <span class="num v">{{ $row['open'] }}</span>
            </div>
        @endforeach
    </div>
    <div class="d-legend" aria-hidden="true"><span><i class="key key-open"></i>Open</span><span><i class="key key-late"></i>Of those, overdue</span></div>
    <p class="chart-note">Counts of open tasks, for sharing work out. Not a measure of how well anyone works.</p>
@endif
