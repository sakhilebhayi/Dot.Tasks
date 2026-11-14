@props(['cards' => []])
{{-- The arrival band's picture: the person's own next four tasks drawn as cards in a job-card rack, in the platform's tokens
     (the card edge, the notch for high and urgent, the red edge for late, the one green for today). Each card is one real task;
     with none, the four slots stand empty. It carries no words, so it is hidden from assistive technology. --}}
<svg {{ $attributes->class(['rack-art']) }} viewBox="0 0 352 112" width="352" height="112" aria-hidden="true" focusable="false">
    @foreach(range(0, 3) as $slot)
        <rect class="ra-slot" x="{{ 2 + $slot * 88 }}" y="2" width="80" height="108" rx="6" />
    @endforeach
    @foreach(array_slice($cards, 0, 4) as $i => $card)
        @php
            $x = 10 + $i * 88;
            $first = max(14, min(40, (int) round($card['title'] * 1.1)));
            $second = max(10, $first - 12);
            $edge = ['late' => 'late', 'today' => 'go'][$card['state']] ?? '';
        @endphp
        <g class="ra-card ra-seat" style="--i:{{ $i }}">
            <rect class="ra-face" x="{{ $x }}" y="10" width="64" height="92" rx="6" />
            @if(in_array($card['priority'], ['high', 'urgent'], true))
                <rect class="{{ $card['state'] === 'today' ? 'ra-go' : 'ra-notch' }}" x="{{ $x }}" y="22" width="{{ $card['priority'] === 'urgent' ? 5 : 3 }}" height="68" />
            @endif
            <rect class="ra-line" x="{{ $x + 12 }}" y="24" width="{{ $first }}" height="4" rx="1" />
            <rect class="ra-line" x="{{ $x + 12 }}" y="34" width="{{ $second }}" height="4" rx="1" />
            @if($card['state'] === 'late')<rect class="ra-edge late" x="{{ $x + 12 }}" y="79" width="40" height="3" />@endif
            <rect class="ra-edge {{ $edge }}" x="{{ $x + 12 }}" y="84" width="40" height="3" />
            <rect class="ra-edge {{ $edge }}" x="{{ $x + 12 }}" y="89" width="40" height="3" />
        </g>
    @endforeach
</svg>
