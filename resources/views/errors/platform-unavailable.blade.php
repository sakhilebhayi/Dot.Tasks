{{--
    Shown with a 503 when InfoDot has switched Dot.Tasks off (maintenance or unavailable). It says so plainly and reads nothing of the
    company's work: the tasks and boards already here are kept exactly as they are. It is not a purchase or permission problem.
--}}
@extends('errors._ecosystem-layout', ['code' => 503, 'quiet' => true])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#8a5800" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.1-.5-.5-2.1z"/></svg>
@endsection

@section('title', 'Dot.Tasks is not available right now')
@section('message', ($message ?? 'Dot.Tasks is temporarily unavailable.').' The tasks and boards already here are kept as they are.')

@section('header-action')
    @if ($signedIn ?? false)
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" style="background-color: transparent; border: 0; padding: 10px 0 1px; min-height: 44px; color: rgba(255,255,255,0.85); font: inherit; font-size: var(--dot-text-body-sm-size); cursor: pointer;">Sign out</button>
        </form>
    @endif
@endsection
