@php
    $weeks = $this->data['trend'];
    $most = max(1, collect($weeks)->max('finished'));
@endphp
@if(collect($weeks)->sum('finished') === 0)
    <p class="widget-empty">Nothing finished in the last 12 weeks.</p>
@else
    <div class="columns" role="img" aria-label="Tasks finished each week. This week {{ end($weeks)['finished'] }}.">
        @foreach($weeks as $week)
            <div class="column" title="Week of {{ $week['label'] }}: {{ $week['finished'] }}">
                <i style="height:{{ max($week['finished'] > 0 ? 3 : 0, 100 * $week['finished'] / $most) }}%"></i>
            </div>
        @endforeach
    </div>
    <div class="column-axis" aria-hidden="true"><span>{{ $weeks[0]['label'] }}</span><span>This week: <b class="num">{{ end($weeks)['finished'] }}</b></span></div>
    <details class="chart-table">
        <summary>See the numbers</summary>
        <table>
            <thead><tr><th scope="col">Week of</th><th scope="col">Finished</th></tr></thead>
            <tbody>
                @foreach($weeks as $week)<tr><td>{{ $week['label'] }}</td><td class="num">{{ $week['finished'] }}</td></tr>@endforeach
            </tbody>
        </table>
    </details>
@endif
