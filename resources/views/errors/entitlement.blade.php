{{--
    Shown with a 403 when InfoDot says the person's company does not hold Dot.Tasks: at sign-in when none of their
    workspaces holds it, and during a session when the current company stops holding it. It reads nothing of the
    company's work. One action: the company's Platforms page in InfoDot.
--}}
@php
    $who = filled($company ?? null) ? $company : 'your company';

    [$heading, $explanation] = match ($status ?? '') {
        'suspended' => ["Dot.Tasks is suspended for {$who}", 'Access was suspended in InfoDot. An owner or admin of the company can see why there and put it right.'],
        'payment_required' => ["Dot.Tasks needs a payment for {$who}", 'A payment is outstanding in InfoDot. An owner or admin of the company can settle it there.'],
        'subscription_expired', 'expired', 'trial_ended', 'cancelled' => ["Dot.Tasks has ended for {$who}", 'The time the company had on Dot.Tasks is over. An owner or admin can start it again in InfoDot.'],
        'company_required', 'no_personal_access' => ['Dot.Tasks needs a company', 'Your personal workspace in InfoDot does not include Dot.Tasks. A company you own or belong to can switch it on.'],
        'not_permitted_for_you' => ['Your company has not given you Dot.Tasks', 'The company chooses who uses Dot.Tasks. An owner or admin can add you in InfoDot.'],
        'temporarily_unavailable', 'not_launched' => ["Dot.Tasks is not available for {$who} right now", 'InfoDot shows where it stands and when it can be used again.'],
        default => ["Dot.Tasks is not switched on for {$who}", 'An owner or admin of the company can switch it on in InfoDot.'],
    };

    $others = $others ?? collect();
@endphp
@extends('errors._ecosystem-layout', ['code' => 403, 'quiet' => true])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#8a5800" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v8"/><path d="M6.6 6.6a8 8 0 1 0 10.8 0"/></svg>
@endsection

@section('title', $heading)
@section('message', $explanation.' The tasks and boards already here are kept as they are.')

@section('header-action')
    @if ($signedIn ?? false)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="link-underline entitlement-quiet" style="background-color: transparent; border: 0; padding: 10px 0 1px; min-height: 44px; color: rgba(255,255,255,0.85); font: inherit; font-size: 14px; cursor: pointer;">Sign out</button>
        </form>
    @endif
@endsection

@section('actions')
    <a href="{{ $manageUrl }}" class="press entitlement-action" data-primary-action style="padding: 12px 24px; background: var(--accent); color: #241c0c; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Open Platforms in InfoDot</a>
@endsection

@section('after')
    <style>
        .entitlement-action:focus-visible, .entitlement-quiet:focus-visible, .entitlement-switch:focus-visible { outline: 2px solid var(--ink); outline-offset: 3px; }
        header .entitlement-quiet:focus-visible { outline-color: #ffffff; }
        .entitlement-switch { transition: background-color 160ms var(--ease-out), transform 160ms var(--ease-out); }
        @media (hover: hover) and (pointer: fine) { .entitlement-switch:hover { background-color: rgba(36,28,12,0.06); } }
        ::selection { background: var(--accent); color: var(--ink); }
    </style>

    @if ($others->isNotEmpty())
        <div style="border-top: 1px solid var(--line); padding-top: 28px;">
            <h2 class="font-display" style="font-size: 16px; font-weight: 600; line-height: 1.5; color: var(--ink); margin: 0 0 14px;">Or work somewhere else</h2>
            <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; justify-content: center; gap: 8px;">
                @foreach ($others as $other)
                    <li>
                        <form method="POST" action="{{ route('current-team.update') }}" style="margin: 0;">
                            @method('PUT')
                            @csrf
                            <input type="hidden" name="team_id" value="{{ $other->id }}">
                            <button type="submit" class="press entitlement-switch" style="min-height: 44px; max-width: 100%; padding: 10px 18px; background-color: transparent; border: 1px solid rgba(36,28,12,0.28); border-radius: 999px; color: var(--ink); font-family: var(--font-display); font-weight: 600; font-size: 14px; cursor: pointer; overflow-wrap: anywhere;">{{ $other->personal_team ? 'Your own workspace' : $other->name }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
