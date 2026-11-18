{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="claim" role="alert">
            <h1 class="claim-title">{{ $headline }}</h1>
            <p class="claim-lede">{{ $body }}</p>
            <div class="claim-actions">
                @if ($retry)<a class="btn primary claim-submit" href="{{ $retryUrl }}">Start again</a>@endif
                <a class="btn claim-submit" href="{{ $homeUrl }}">Back to Dot.Tasks</a>
            </div>
            <p class="claim-help">Reference: {{ $code }}</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
