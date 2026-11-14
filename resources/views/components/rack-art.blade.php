@props(['cards' => 3])
{{-- The arrival band's artwork: four slots of a job-card rack, drawn from the platform's own tokens (the card edge, the notch,
     the one green). Decorative only, so it is hidden from assistive technology. With no cards it shows the empty rack. --}}
<svg {{ $attributes->class(['rack-art']) }} viewBox="0 0 352 112" width="352" height="112" aria-hidden="true" focusable="false">
    @foreach(range(0, 3) as $slot)
        <rect class="ra-slot" x="{{ 2 + $slot * 88 }}" y="2" width="80" height="108" rx="6" />
    @endforeach
    @if($cards >= 1)
        <g class="ra-card" style="--i:0">
            <rect class="ra-face" x="94" y="10" width="64" height="92" rx="6" />
            <rect class="ra-notch" x="94" y="22" width="3" height="68" />
            <rect class="ra-line" x="106" y="24" width="40" height="4" rx="1" />
            <rect class="ra-line" x="106" y="34" width="28" height="4" rx="1" />
            <rect class="ra-edge" x="106" y="84" width="40" height="3" />
            <rect class="ra-edge" x="106" y="89" width="40" height="3" />
        </g>
    @endif
    @if($cards >= 2)
        <g class="ra-card" style="--i:1">
            <rect class="ra-face" x="182" y="10" width="64" height="92" rx="6" />
            <rect class="ra-line" x="194" y="24" width="40" height="4" rx="1" />
            <rect class="ra-line" x="194" y="34" width="34" height="4" rx="1" />
            <rect class="ra-edge" x="194" y="89" width="40" height="3" />
        </g>
    @endif
    @if($cards >= 3)
        <g class="ra-card ra-seat" style="--i:2">
            <rect class="ra-face" x="270" y="10" width="64" height="92" rx="6" />
            <rect class="ra-go" x="270" y="22" width="3" height="68" />
            <rect class="ra-line" x="282" y="24" width="40" height="4" rx="1" />
            <rect class="ra-line" x="282" y="34" width="22" height="4" rx="1" />
            <rect class="ra-edge go" x="282" y="89" width="40" height="3" />
        </g>
    @endif
</svg>
