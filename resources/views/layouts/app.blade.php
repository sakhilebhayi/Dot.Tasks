<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Dot.Tasks</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/signed-in.css', 'resources/css/job-card.css', 'resources/js/app.js'])
    <script>
        (function () {
            var stored = null;
            try { stored = localStorage.getItem('dot-theme'); } catch (e) {}
            if (stored === 'dark' || (!stored && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @livewireStyles
</head>
<body class="app">
    <div data-shell-page><x-banner /></div>

    <aside id="shell-drawer" class="sidebar" aria-label="Dot.Tasks navigation">
        <div class="brand">
            <img src="{{ asset('images/mark-outlined.png') }}" alt="">
            <b>Dot.Tasks</b>
            <button type="button" class="shell-drawer-close" data-shell-close>
                <x-icon name="close" /> Close
            </button>
        </div>

        <nav class="nav" aria-label="Primary">
            @foreach ([
                ['tasks.today', 'day', 'My Day'],
                ['tasks.inbox', 'inbox', 'Inbox'],
                ['tasks.mine', 'check', 'My tasks'],
                ['tasks.upcoming', 'calendar', 'Upcoming'],
                ['tasks.team', 'team', 'Team'],
                ['dashboard', 'home', 'Overview'],
                ['tasks.templates', 'copy', 'Templates'],
                ['tasks.automation', 'bolt', 'Automation'],
                ['labels.index', 'label', 'Labels and fields'],
                ['removed.index', 'trash', 'Removed'],
            ] as [$route, $icon, $label])
                <a href="{{ route($route) }}" class="nav-item {{ request()->routeIs($route) ? 'active' : '' }}" @if (request()->routeIs($route)) aria-current="page" @endif>
                    <x-icon :name="$icon" /> {{ $label }}
                </a>
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
                    <p style="padding:0 8px;color:var(--muted);font-size:12px">No boards yet.</p>
                @endforelse
            @endauth
        </nav>

        @auth
        <div class="sidebar-foot">
            <a class="who" href="{{ route('profile.show') }}">
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
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-btn">Log out</button>
            </form>
        </div>
        @endauth
    </aside>

    <button type="button" id="shell-backdrop" class="shell-backdrop" tabindex="-1" aria-label="Close menu" data-shell-close></button>

    <header class="topbar" data-shell-page>
        <button type="button" id="shell-menu-button" class="shell-menu-btn" aria-controls="shell-drawer" aria-expanded="false">
            <x-icon name="menu" />
            <span>Menu</span>
        </button>
        @auth
            <button type="button" class="find" data-open-search><x-icon name="search" /> <span>Find a task</span> <kbd>/</kbd></button>
            <div class="grow"></div>
            <button type="button" class="btn primary" data-open-quick-add><x-icon name="plus" /> <span class="topbar-hide">New task</span></button>
            <livewire:notification-bell />
        @endauth
        <button type="button" id="theme-toggle" class="icon-btn" aria-label="Switch between light and dark"><x-icon name="moon" /></button>
    </header>

    <div class="content-wrap" data-shell-page>
        <main>{{ $slot }}</main>
    </div>

    @auth
        <livewire:tasks.task-detail />
        <livewire:tasks.quick-add :board="request()->route('taskList')?->id" />
        <livewire:tasks.find-tasks />
        <p class="sr" id="drag-help">Press Enter to open. Hold Alt and use the arrow keys to move it.</p>
        <p class="sr" id="person-help">Press Enter to open. Hold Alt and use the left and right arrows to hand it to someone else.</p>
        <p class="sr" id="date-help">Press Enter to open. Hold Alt and use the arrow keys to change its date by a day or a week.</p>
        <p class="sr" id="bar-help">Press Enter to open. Hold Alt and use the left and right arrows to move it. Add Shift to change its length.</p>
        <div class="sr" id="announce" role="status" aria-live="polite"></div>
    @endauth

    @stack('modals')
    @livewireScripts
</body>
</html>
