{{-- R-54: the claim page, in The Job Card. Shown when an account here already uses the email address of the InfoDot sign-in.
     Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="claim">
            <h1 class="claim-title">Confirm it is you</h1>
            <p class="claim-lede">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your password and your boards stay as they are.</p>

            @if (session('dotsso_status'))
                <p class="claim-status" role="status">{{ session('dotsso_status') }}</p>
            @endif

            <form method="POST" action="{{ $verifyUrl }}" class="claim-form" novalidate>
                @csrf
                <x-label for="code" value="Code from the email" />
                <x-input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                         maxlength="{{ $codeLength }}" required autofocus
                         class="claim-code" aria-describedby="code-help code-error"
                         :aria-invalid="$errors->has('code') ? 'true' : 'false'" />
                <p id="code-help" class="claim-help">It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries.</p>
                <p id="code-error" class="claim-error" role="alert">{{ $errors->first('code') }}</p>

                <x-button class="claim-submit">Confirm and continue</x-button>
            </form>

            <div class="claim-aside">
                <form method="POST" action="{{ $resendUrl }}">@csrf<button type="submit" class="btn quiet claim-link">Send a new code</button></form>
                <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="btn quiet claim-link">Cancel</button></form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
