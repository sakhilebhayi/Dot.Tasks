<div>
    @php
        $r = $this->report;
        $defs = \App\Services\TaskReports::DEFINITIONS;
        $time = function (?int $minutes) {
            return match (true) {
                $minutes === null => 'None yet',
                $minutes >= 1440 => round($minutes / 1440, 1).' days',
                $minutes >= 60 => round($minutes / 60, 1).' hours',
                default => $minutes.' minutes',
            };
        };
        $weeks = $r['trend'];
        $most = max(1, collect($weeks)->max('finished'));
        $names = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'Review', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
    @endphp
    <div class="filters">
        <select class="select bare" wire:model.live="period" aria-label="Period">
            @foreach(\App\Livewire\Reports::PERIODS as $value => $name)<option value="{{ $value }}">{{ $name }}</option>@endforeach
        </select>
        @if($period === 'custom')
            <input class="input" style="width:auto" type="date" aria-label="From" wire:model.live="from">
            <input class="input" style="width:auto" type="date" aria-label="To" wire:model.live="to">
        @endif
        <select class="select bare" wire:model.live="board" aria-label="Board">
            <option value="">Every board</option>
            @foreach($this->boards as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
        </select>
        @if($this->fields->isNotEmpty())
            <select class="select bare" wire:model.live="field" aria-label="Split open work by">
                <option value="">No split</option>
                @foreach($this->fields as $option)<option value="{{ $option->id }}">By {{ $option->name }}</option>@endforeach
            </select>
        @endif
        <a class="btn" style="margin-left:auto" href="{{ route('reports.export', ['period' => $period, 'from' => $from, 'to' => $to, 'board' => $board]) }}">Download CSV</a>
    </div>
    <p style="margin-bottom:12px;color:var(--muted)">{{ $this->range[0]->format('j M Y') }} to {{ $this->range[1]->format('j M Y') }}</p>

    <div class="report-grid">
        @foreach([
            ['Created', $r['created'], $defs['created']],
            ['Finished', $r['finished'], $defs['finished']],
            ['Overdue now', $r['overdue'], $defs['overdue']],
            ['Blocked now', $r['blocked'], $defs['blocked']],
            ['Reopened', $r['reopened'], $defs['reopened']],
            ['Average time to finish', $time($r['average_minutes']), $defs['average']],
            ['Median time to finish', $time($r['median_minutes']), $defs['median']],
            ['Repeating tasks on time', $r['on_time'] ? $r['on_time']['on_time'].' of '.$r['on_time']['of'] : 'None yet', $defs['on_time']],
        ] as [$name, $value, $meaning])
            <div class="report-figure">
                <span>{{ $name }}</span>
                <b class="num">{{ $value }}</b>
                <p>{{ $meaning }}</p>
            </div>
        @endforeach
    </div>

    <div class="widgets" style="margin-top:12px">
        <section class="widget wide" aria-label="Finished over time">
            <div class="widget-head"><h2>Finished over time</h2></div>
            @if(collect($weeks)->sum('finished') === 0)
                <p class="widget-empty">Nothing finished in this period.</p>
            @else
                <div class="columns" role="img" aria-label="Tasks finished in this period, {{ $r['finished'] }} in all">
                    @foreach($weeks as $week)
                        <div class="column" title="{{ $week['label'] }}: {{ $week['finished'] }}"><i style="height:{{ max($week['finished'] > 0 ? 3 : 0, 100 * $week['finished'] / $most) }}%"></i></div>
                    @endforeach
                </div>
                <div class="column-axis" aria-hidden="true"><span>{{ $weeks[0]['label'] }}</span><span>{{ end($weeks)['label'] }}</span></div>
                <details class="chart-table">
                    <summary>See the numbers</summary>
                    <table>
                        <thead><tr><th scope="col">From</th><th scope="col">Finished</th></tr></thead>
                        <tbody>@foreach($weeks as $week)<tr><td>{{ $week['label'] }}</td><td class="num">{{ $week['finished'] }}</td></tr>@endforeach</tbody>
                    </table>
                </details>
            @endif
        </section>
        <section class="widget" aria-label="Open by status">
            <div class="widget-head"><h2>Open by status</h2></div>
            <table class="plain-table"><tbody>
                @forelse($r['by_status'] as $name => $total)<tr><th scope="row">{{ $names[$name] ?? $name }}</th><td class="num">{{ $total }}</td></tr>@empty<tr><td>No open work.</td></tr>@endforelse
            </tbody></table>
        </section>
        <section class="widget" aria-label="Open by priority">
            <div class="widget-head"><h2>Open by priority</h2></div>
            <table class="plain-table"><tbody>
                @forelse($r['by_priority'] as $name => $total)<tr><th scope="row">{{ $names[$name] ?? $name }}</th><td class="num">{{ $total }}</td></tr>@empty<tr><td>No open work.</td></tr>@endforelse
            </tbody></table>
        </section>
        <section class="widget" aria-label="Workload by person">
            <div class="widget-head"><h2>Open work by person</h2></div>
            <table class="plain-table">
                <thead><tr><th scope="col">Person</th><th scope="col">Open</th><th scope="col">Overdue</th><th scope="col">Hours estimated</th></tr></thead>
                <tbody>
                    @forelse($r['by_person'] as $row)
                        <tr><th scope="row">{{ $row['name'] }}</th><td class="num">{{ $row['open'] }}</td><td class="num">{{ $row['overdue'] }}</td><td class="num">{{ $row['hours'] }}</td></tr>
                    @empty
                        <tr><td colspan="4">No open work.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="chart-note">For sharing work out. In name order, because this is not a ranking.</p>
        </section>
        <section class="widget" aria-label="Estimated and actual time">
            <div class="widget-head"><h2>Estimated and actual time</h2></div>
            @if($r['estimate']['tasks'] === 0)
                <p class="widget-empty">No finished task has both an estimate and time spent.</p>
            @else
                <table class="plain-table"><tbody>
                    <tr><th scope="row">Tasks measured</th><td class="num">{{ $r['estimate']['tasks'] }}</td></tr>
                    <tr><th scope="row">Estimated</th><td class="num">{{ round($r['estimate']['estimated'] / 60, 1) }} hours</td></tr>
                    <tr><th scope="row">Actual</th><td class="num">{{ round($r['estimate']['actual'] / 60, 1) }} hours</td></tr>
                </tbody></table>
            @endif
            <p class="chart-note">{{ $defs['estimate'] }}</p>
        </section>
        @if($r['by_field'] !== [])
            <section class="widget" aria-label="Open work by field">
                <div class="widget-head"><h2>Open work by {{ $this->fields->firstWhere('id', (int) $field)?->name }}</h2></div>
                <table class="plain-table"><tbody>
                    @foreach($r['by_field'] as $name => $total)<tr><th scope="row">{{ $name }}</th><td class="num">{{ $total }}</td></tr>@endforeach
                </tbody></table>
            </section>
        @endif
    </div>
</div>
