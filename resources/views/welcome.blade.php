@php
    // Public sign-up here is closed: an account starts on InfoDot, and this app makes its side at the first sign-in.
    $open = \Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::registration());
    $join = $open ? route('register') : config('ecosystem.signup_url');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Tasks: work you can move by hand</title>
        <meta name="description" content="Tasks for teams, on cards you move by hand: a board, a plan for today, a calendar, a timeline, and rules that take the admin away.">

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/job-card.css', 'resources/css/landing.css'])
    </head>
    <body class="app landing">
        <header class="l-wrap l-top">
            <a class="l-brand" href="/"><img src="{{ asset('images/mark-outlined.png') }}" alt=""> Dot.Tasks</a>
            <nav aria-label="Account">
                @auth
                    <a class="btn primary" href="{{ url('/dashboard') }}">Open Dot.Tasks</a>
                @else
                    <a class="btn quiet" href="{{ route('login') }}">Sign in</a>
                    <a class="btn primary l-join" href="{{ $join }}">Create account</a>
                @endauth
            </nav>
        </header>

        <main>
            <section class="l-wrap l-hero">
                <div>
                    <h1>Work you can move by hand.</h1>
                    <p>Dot.Tasks keeps a team's work on cards: on a board, in a plan for today, and on a timeline. Move a card and everyone sees where it stands.</p>
                    <div class="l-actions">
                        @auth
                            <a class="btn primary" href="{{ url('/dashboard') }}">Open Dot.Tasks</a>
                        @else
                            <a class="btn primary" href="{{ $join }}">Create account</a>
                            <a class="btn" href="{{ route('login') }}">Sign in</a>
                        @endauth
                    </div>
                    @guest
                        @unless($open)<p class="l-note">Accounts are made on InfoDot. Dot.Tasks opens from there.</p>@endunless
                    @endguest
                </div>
                <div class="l-rack" aria-hidden="true">
                    <div class="l-col">
                        <h2>To Do <span style="color:var(--muted);font-weight:400">3</span></h2>
                        <div class="card l-fly"><span class="notch high"></span><p class="card-title">Inspect the haul road</p><div class="card-meta"><span class="num">Today</span><span class="person">T</span></div></div>
                        <div class="card"><p class="card-title">Renew the fleet insurance</p><div class="card-meta"><span class="num">Fri</span></div></div>
                        <div class="card"><p class="card-title">Book the safety training</p><div class="card-meta"><span class="num">Nov 18</span><span class="person">J</span></div></div>
                    </div>
                    <div class="l-col">
                        <h2>In Progress <span style="color:var(--muted);font-weight:400">2</span></h2>
                        <div class="l-open"></div>
                        <div class="card"><p class="card-title">Prepare the monthly report</p><div class="card-meta"><span class="num">1/2 subtasks</span><span class="person">J</span></div></div>
                        <div class="card"><p class="card-title">Service the crusher</p><p class="blocked-line">Blocked</p></div>
                    </div>
                    <div class="l-col">
                        <h2>Done <span style="color:var(--muted);font-weight:400">2</span></h2>
                        <div class="card is-done"><p class="card-title">Order conveyor spares</p></div>
                        <div class="card is-done"><p class="card-title">Close out the shift handover</p></div>
                    </div>
                </div>
            </section>

            <section class="l-band">
                <div class="l-wrap l-split">
                    <div>
                        <h2>Start the day knowing what comes first.</h2>
                        <p class="l-lead">My Day shows your week as a rack of cards and suggests what to do first, with the reason beside it. Drag work into your plan, in the order you mean to do it.</p>
                    </div>
                    <div class="l-frame" aria-hidden="true">
                        <div class="week-rack">
                            @foreach([['Mon', 2, 'past', 1], ['Tue', 3, 'past', 0], ['Wed', 4, 'today', 3], ['Thu', 5, '', 2], ['Fri', 6, '', 4], ['Sat', 7, '', 0], ['Sun', 8, '', 0]] as [$name, $number, $state, $edges])
                                <div class="week-slot {{ $state }}">
                                    <span class="week-name">{{ $name }} <b>{{ $number }}</b></span>
                                    <span class="edges">@for($i = 0; $i < $edges; $i++)<i></i>@endfor</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="day-card"><span class="day-title"><b>Service the pump</b><span class="l-why">2 days overdue. 2 other tasks waiting on this.</span></span></div>
                        <div class="day-card"><span class="day-title"><b>Inspect the haul road</b><span class="l-why">Urgent priority. Due today.</span></span></div>
                    </div>
                </div>
            </section>

            <section class="l-band">
                <div class="l-wrap">
                    <h2>One task, however you want to see it.</h2>
                    <div class="l-ways">
                        <div><h3>Board</h3><p>Your own columns, with lanes by person or priority.</p></div>
                        <div><h3>List</h3><p>Edit in the row. Change many at once.</p></div>
                        <div><h3>Calendar</h3><p>Drag a card to a new day.</p></div>
                        <div><h3>Timeline</h3><p>Stretch a bar. See what waits for what.</p></div>
                        <div><h3>Team</h3><p>See who holds what, and hand work over.</p></div>
                    </div>
                </div>
            </section>

            <section class="l-band">
                <div class="l-wrap l-split">
                    <div>
                        <h2>Let the rules do the chasing.</h2>
                        <p class="l-lead">Write a rule in plain choices and read it back as a sentence before you turn it on. A rule can only do what the person who wrote it could do, and it keeps a record of everything it did.</p>
                    </div>
                    <div class="l-frame" aria-hidden="true">
                        <p class="l-rule">When a task becomes overdue on Maintenance, if priority is urgent, wait 2 hours, then give it to the shift lead and notify the person who made it.</p>
                        <p class="l-log"><b>Done</b> on Service the pump. Gave it to the shift lead. Notified the person who made it.</p>
                    </div>
                </div>
            </section>

            <section class="l-band">
                <div class="l-wrap">
                    <h2>Made for work that has to be right.</h2>
                    <div class="l-facts">
                        <div><h3>Finished means finished</h3><p>A task waits for its blockers and its approvals, and history shows who did what.</p></div>
                        <div><h3>Yours until you share it</h3><p>Everyone has a private Inbox. Nobody else can see it, including the owner of the team.</p></div>
                        <div><h3>Counts, not scores</h3><p>Reports show how work moved, with each measure defined. Nothing ranks people.</p></div>
                    </div>
                </div>
            </section>

            <section class="l-end">
                <div class="l-wrap">
                    <h2>Put your first card on the rack.</h2>
                    <p>Make a board, type what needs doing, and move it.</p>
                    <div class="l-actions">
                        @auth
                            <a class="btn" href="{{ url('/dashboard') }}">Open Dot.Tasks</a>
                        @else
                            <a class="btn" href="{{ $join }}">Create account</a>
                            <a class="btn quiet" href="{{ route('login') }}">Sign in</a>
                        @endauth
                    </div>
                </div>
            </section>
        </main>

        <footer class="l-wrap l-foot">
            <span>&copy; {{ date('Y') }} Dot.Tasks</span>
            <span style="display:flex;gap:12px">
                @if (Route::has('terms.show'))<a href="{{ route('terms.show') }}">Terms</a>@endif
                @if (Route::has('policy.show'))<a href="{{ route('policy.show') }}">Privacy</a>@endif
                <a href="{{ url('/cookies') }}">Cookies</a>
            </span>
        </footer>
    </body>
</html>
