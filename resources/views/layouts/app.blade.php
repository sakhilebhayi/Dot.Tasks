<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Dot.Tasks</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    {{-- Shared Dot UI 0.6.1 (Roboto, type scale, tokens, search pill, platform switcher, header) as ONE minified stylesheet from this site's own
         public/dot-ui. First stylesheet in the head, before the app CSS, so the mapping block in job-card.css wins. No font preload: the largest
         paint on the dashboard is not text (PERFORMANCE.md). --}}
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
    @vite(['resources/css/signed-in.css', 'resources/css/job-card.css', 'resources/js/app.js'])
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var stored = null;
            try { stored = localStorage.getItem('dot-theme'); } catch (e) {}
            if (stored === 'dark' || (stored !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @livewireStyles
</head>
<body class="app">
    @php
        $user = Auth::user();
        $team = $user?->currentTeam;
        $unread = $user ? $user->unreadNotifications()->count() : 0;
        $account = $user ? [
            'name' => $user->name,
            'email' => $user->email,
            'team' => $team?->name,
            'links' => array_values(array_filter([
                ['label' => 'Profile and settings', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.*')],
                $team ? ['label' => 'Team settings', 'href' => route('teams.show', $team->id), 'current' => request()->routeIs('teams.show')] : null,
                Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->can('create', Laravel\Jetstream\Jetstream::newTeamModel()) ? ['label' => 'Create new team', 'href' => route('teams.create')] : null,
                ['label' => 'Notifications', 'href' => route('notifications.index'), 'badge' => $unread > 0 ? ($unread > 99 ? '99+' : $unread) : null],
            ])),
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ] : null;
    @endphp

    {{-- The shared Dot header (dot-ui 0.5.0). Dot.Tasks keeps its sidebar as the primary navigation, so the bar has no nav list of its own
         (drawer-target adopts the sidebar as the phone menu). The search is centred by the header's grid. --}}
    <x-dot.header id="dh" :brand-href="$user ? route('dashboard') : url('/')" brand-label="Dot.Tasks home" drawer-target="shell-drawer" skip="#main"
                  :context="$team && ! $team->personal_team ? $team->name : null" :account="$account">
        <x-slot:brand>
            <img src="{{ asset('images/mark-outlined.png') }}" alt="" width="26" height="26">
            <span class="dh-brand__name">Dot.Tasks</span>
        </x-slot:brand>
        @auth
            <x-slot:search>
                <livewire:tasks.header-search />
            </x-slot:search>
            <x-slot:tools>
                {{-- On the overview the page itself carries the one filled action, so this one steps back. --}}
                <button type="button" @class(['btn', 'tk-new', 'primary' => ! request()->routeIs('dashboard')]) data-open-quick-add aria-label="New task"><x-icon name="plus" /> <span class="topbar-hide">New task</span></button>
                <x-dot.switcher platform="tasks" :company="$team?->personal_team ? null : $team?->infodot_team_uuid" :company-name="$team?->name" logos="/dot-ui/img/platforms" />
                <livewire:notification-bell />
            </x-slot:tools>
        @endauth
    </x-dot.header>

    <div data-shell-page><x-banner /></div>

    <aside id="shell-drawer" class="sidebar" aria-label="Dot.Tasks navigation">
        {{-- Phones: the header's menu button opens this sidebar (dot-ui drawer-target). The brand row only carries the close button. --}}
        <div class="brand">
            <img src="{{ asset('images/mark-outlined.png') }}" alt="">
            <b>Dot.Tasks</b>
            <button type="button" class="shell-drawer-close" data-dh-close>
                <x-icon name="close" /> Close
            </button>
        </div>

        <nav class="nav" aria-label="Primary">
            @auth
                {{-- Under 480px the bar has no room for these two; the drawer carries them (desktop shows them in the bar). --}}
                <div class="nav-phone">
                    <a href="{{ route('tasks.inbox') }}" class="nav-item" data-open-quick-add><x-icon name="plus" /> New task</a>
                    <a href="{{ route('notifications.index') }}" class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}"><x-icon name="bell" /> Notifications @if($unread > 0)<span class="count">{{ $unread }}</span>@endif</a>
                </div>
            @endauth
            @foreach ([
                '' => [['tasks.today', 'day', 'My Day'], ['tasks.inbox', 'inbox', 'Inbox'], ['tasks.mine', 'check', 'My tasks'], ['tasks.upcoming', 'calendar', 'Upcoming']],
                'Team' => [['dashboard', 'home', 'Overview'], ['tasks.team', 'team', 'Who holds what'], ['reports.index', 'report', 'Reports']],
                'Set up' => [['tasks.templates', 'copy', 'Templates'], ['tasks.automation', 'bolt', 'Automation'], ['labels.index', 'label', 'Labels and fields'], ['removed.index', 'trash', 'Removed']],
            ] as $group => $items)
                @php
                    if ($group === 'Set up' && Auth::user()?->currentTeam && Auth::user()->hasTeamPermission(Auth::user()->currentTeam, 'delete')) {
                        $items = [...$items, ['tasks.connections', 'link', 'Connections'], ['tasks.audit', 'list', 'Audit log']];
                    }
                @endphp
                @if($group !== '')
                    <div class="nav-group"><span>{{ $group }}</span></div>
                @endif
                @foreach ($items as [$route, $icon, $label])
                    <a href="{{ route($route) }}" class="nav-item {{ request()->routeIs($route) ? 'active' : '' }}" @if (request()->routeIs($route)) aria-current="page" @endif>
                        <x-icon :name="$icon" /> {{ $label }}
                    </a>
                @endforeach
            @endforeach

            @auth
                @php $boards = Auth::user()->currentTeam?->boards()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color']) ?? collect(); @endphp
                <div class="nav-group">
                    <span>Boards</span>
                    @can('create', App\Models\TaskList::class)
                        <a href="{{ route('task-lists.create') }}" aria-label="New board"><x-icon name="plus" /></a>
                    @endcan
                </div>
                @forelse ($boards as $board)
                    <a href="{{ route('task-lists.show', $board) }}" data-board="{{ $board->id }}" class="nav-item {{ request()->route('taskList')?->is($board) ? 'active' : '' }}">
                        <span class="nav-dot" style="background: {{ $board->color }}"></span>
                        <span style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis">{{ $board->name }}</span>
                    </a>
                @empty
                    <p style="padding:0 8px;color:var(--muted);font-size:var(--dot-text-caption-size)">No boards yet.</p>
                @endforelse
            @endauth
        </nav>

        @auth
        <div class="sidebar-foot">
            <a class="who nav-phone" href="{{ route('profile.show') }}">
                <span class="person">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                <div>
                    <b>{{ Auth::user()->name }}</b>
                    <span>Profile and settings</span>
                </div>
            </a>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
                <details class="team-switch">
                    <summary>
                        <span>{{ Auth::user()->currentTeam->name }}</span>
                        <x-icon name="chevron" />
                    </summary>
                    <div>
                        <a class="team-row" href="{{ route('teams.show', Auth::user()->currentTeam->id) }}">Team settings</a>
                        @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                            <a class="team-row" href="{{ route('teams.create') }}">Create new team</a>
                        @endcan
                        @if (Auth::user()->allTeams()->count() > 1)
                            @foreach (Auth::user()->allTeams() as $switchTeam)
                                <form method="POST" action="{{ route('current-team.update') }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                                    <button type="submit" class="team-row" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>
                                        {{ Auth::user()->isCurrentTeam($switchTeam) ? $switchTeam->name.' (current)' : 'Switch to '.$switchTeam->name }}
                                    </button>
                                </form>
                            @endforeach
                        @endif
                    </div>
                </details>
            @endif
            <form method="POST" action="{{ route('logout') }}" class="nav-phone">
                @csrf
                <button type="submit" class="logout-btn">Log out</button>
            </form>
        </div>
        @endauth
    </aside>

    <div class="content-wrap">
        <main id="main" tabindex="-1">
            {{ $slot }}
            @auth
                <p class="sr" id="drag-help">Press Enter to open. Hold Alt and use the arrow keys to move it.</p>
                <p class="sr" id="person-help">Press Enter to open. Hold Alt and use the left and right arrows to hand it to someone else.</p>
                <p class="sr" id="date-help">Press Enter to open. Hold Alt and use the arrow keys to change its date by a day or a week.</p>
                <p class="sr" id="bar-help">Press Enter to open. Hold Alt and use the left and right arrows to move it. Add Shift to change its length.</p>
                <div class="sr" id="announce" role="status" aria-live="polite"></div>
            @endauth
        </main>
    </div>

    @auth
        <livewire:tasks.task-detail />
        <livewire:tasks.quick-add :board="request()->route('taskList')?->id" />
        <livewire:tasks.find-tasks />
    @endauth

    <div class="flash connection" id="connection" role="alert" hidden>Connection lost. Keep this page open: edits save when it returns.</div>

    @stack('modals')
    {{-- One file for search, switcher and nav; the results controller loads on first use (it is not needed here: the host drives its own list). --}}
    <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    @livewireScripts
</body>
</html>
