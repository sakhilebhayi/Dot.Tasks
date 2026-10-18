<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Tasks</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } }</script>
    {{-- Only Echo/Reverb (task board realtime updates) needs the Vite bundle here --
         Tailwind/Alpine stay CDN-loaded, unchanged, per this layout's existing convention. --}}
    @vite(['resources/js/app.js'])
    <script>
        // Dark mode: this dashboard's own markup is intentionally fixed-dark (inline styles,
        // not Tailwind dark: classes), so this toggle mainly affects Jetstream-rendered pages
        // (Profile, Team Settings) which do use dark: classes. Kept for theme consistency
        // ecosystem-wide and so those subpages respect the user's light/dark preference.
        (function () {
            const stored = localStorage.getItem('dot-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        :root { --accent: #22c55e; --accent-rgb: 34,197,94; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:#09090b; color:#f4f4f5; font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }

        /* Sidebar */
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; background:#0d0d10; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(34,197,94,0.1) 0%, transparent 65%); pointer-events:none; }

        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        .brand-icon { width:36px; height:36px; border-radius:10px; background:rgba(34,197,94,0.12); border:1px solid rgba(34,197,94,0.22); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .brand-icon .material-symbols-rounded { font-size:18px; color:#22c55e; }
        .brand-name { font-family:'Syne',sans-serif; font-size:14.5px; font-weight:700; color:#f4f4f5; letter-spacing:-0.01em; line-height:1.2; }
        .brand-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#22c55e; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(34,197,94,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(34,197,94,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:#3f3f46; text-transform:uppercase; letter-spacing:0.09em; }

        .sidebar-divider { height:1px; background:rgba(255,255,255,0.06); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:#3f3f46; text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:#71717a; text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; transform:translateX(1px); }
        .nav-item.active { background:rgba(34,197,94,0.1); color:#22c55e; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }

        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(34,197,94,0.18); border:1px solid rgba(34,197,94,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#22c55e; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:#d4d4d8; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:#52525b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Topbar */
        .topbar { position:fixed; top:0; left:260px; right:0; height:54px; background:rgba(9,9,11,0.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#f4f4f5; flex:1; }
        .topbar-team { font-size:11px; color:#52525b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color:#71717a; cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(255,255,255,0.09); color:#d4d4d8; }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* Content */
        .content-wrap { margin-left:260px; padding-top:54px; min-height:100vh; }

        /* Shared UI tokens */
        .dot-card { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
        .dot-card:hover { border-color:rgba(255,255,255,0.11); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; }
        .dot-input { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:8px; color:#f4f4f5; font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(34,197,94,0.45); box-shadow:0 0 0 3px rgba(34,197,94,0.07); }
        .dot-input::placeholder { color:#3f3f46; }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#22c55e; color:#09090b; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:rgba(255,255,255,0.06); color:#a1a1aa; border:1px solid rgba(255,255,255,0.08); }
        .dot-btn-ghost:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(34,197,94,0.12); color:#22c55e; }
        select.dot-input option { background:#1a1a1f; }
        /* ---- Responsive shell -------------------------------------------------
           Breakpoint: below 860px (max-width:859.98px) the sidebar is an off-canvas
           drawer (#shell-drawer) opened from #shell-menu-button in the top bar;
           from 860px (min-width:860px) the layout above is unchanged. Keep both
           numbers in step if you ever move it. See README "Responsive shell". */
        :root { --topbar-h: 54px; }
        .topbar { height:var(--topbar-h); }
        .content-wrap { padding-top:var(--topbar-h); }
        .shell-menu-btn, .shell-drawer-close, .shell-backdrop { display:none; }
        .shell-menu-btn, .shell-drawer-close { align-items:center; justify-content:center; gap:8px; min-width:44px; min-height:44px; padding:0 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:#d4d4d8; font:600 13px/1 'Inter',system-ui,sans-serif; cursor:pointer; flex-shrink:0; }
        .shell-menu-btn:hover, .shell-drawer-close:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .shell-menu-btn svg, .shell-drawer-close svg { width:18px; height:18px; flex-shrink:0; }
        .shell-menu-btn:focus-visible, .shell-drawer-close:focus-visible, .sidebar a:focus-visible, .sidebar button:focus-visible, .sidebar summary:focus-visible { outline:2px solid #22c55e; outline-offset:2px; }

        /* Sidebar footer: team switch + log out (the legacy Jetstream bar that held them sat hidden under the top bar) */
        .sidebar-footer { max-height:55vh; overflow-y:auto; }
        .team-switch summary { list-style:none; display:flex; align-items:center; gap:8px; min-height:36px; padding:6px; border-radius:8px; cursor:pointer; color:#a1a1aa; font-size:12px; font-weight:600; }
        .team-switch summary::-webkit-details-marker { display:none; }
        .team-switch summary:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; }
        .team-switch summary span:first-child { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .team-switch[open] summary { color:#f4f4f5; }
        .team-switch .team-row, .team-switch a.team-row, .logout-btn { display:flex; align-items:center; gap:8px; width:100%; min-height:36px; padding:6px; border:0; border-radius:8px; background:none; color:#a1a1aa; font:500 12px/1.3 'Inter',system-ui,sans-serif; text-align:left; text-decoration:none; cursor:pointer; }
        .team-switch .team-row:hover, .logout-btn:hover { background:rgba(255,255,255,0.05); color:#f4f4f5; }
        .team-switch .team-row[aria-current="true"] { color:#22c55e; font-weight:600; }
        .team-switch .team-row .team-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .logout-btn { margin-top:2px; }

        /* Reusable page pieces (desktop values equal the inline styles they replaced) */
        .page-pad { padding:2rem 2.5rem; }
        .page-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:2rem; }
        .page-head > * { min-width:0; }
        .page-head h1 { overflow-wrap:anywhere; }
        .kpi-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1.25rem; }
        .kpi-tile { min-width:0; background:rgba(20,20,22,0.9); border:1px solid rgba(255,255,255,0.07); border-radius:1rem; padding:1.5rem; }
        .list-card { display:block; min-width:0; background:rgba(20,20,22,0.9); border:1px solid rgba(255,255,255,0.07); border-radius:1rem; padding:1.5rem; text-decoration:none; transition:border-color 0.2s,box-shadow 0.2s; }
        .list-card:hover, .list-card:focus-visible { border-color:rgba(41,98,255,0.4); box-shadow:0 0 0 1px rgba(41,98,255,0.2); }

        /* Wide tables and the kanban board scroll inside their own box, never the page */
        .table-scroll { max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .kanban-scroll { position:relative; max-width:100%; overflow-x:auto; overscroll-behavior-x:contain; -webkit-overflow-scrolling:touch; scroll-snap-type:x proximity; }
        .kanban-scroll > .kanban-col { scroll-snap-align:start; }
        .kanban-move { width:100%; min-height:32px; font-size:12px; border-radius:6px; }

        @media (max-width:859.98px) {
            html.shell-locked, html.shell-locked body { overflow:hidden; }
            .topbar { left:0; padding:0 10px; gap:8px; }
            .topbar-title { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .topbar-title > * { margin:0; font-size:15px; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .topbar-team, .topbar-profile { display:none; }
            .topbar-btn { width:44px; height:44px; border-radius:8px; }
            .topbar-btn .material-symbols-rounded { font-size:20px; }
            .shell-menu-btn { display:inline-flex; }
            .content-wrap { margin-left:0; }

            .sidebar { width:min(300px,86vw); height:100vh; height:100dvh; z-index:60; visibility:hidden; transform:translateX(-100%); transition:transform .22s ease, visibility 0s linear .22s; }
            .sidebar.is-open { visibility:visible; transform:none; transition:transform .22s ease, visibility 0s; box-shadow:0 0 48px rgba(0,0,0,0.6); }
            .sidebar-brand { padding-right:12px; }
            .shell-drawer-close { display:inline-flex; margin-left:auto; padding:0 10px; }
            .sidebar-nav { overscroll-behavior:contain; }
            .nav-item { min-height:44px; padding:10px; }
            .nav-item:hover { transform:none; }
            .shell-backdrop { display:block; position:fixed; inset:0; z-index:55; background:rgba(0,0,0,0.62); opacity:0; visibility:hidden; transition:opacity .22s ease, visibility 0s linear .22s; }
            .shell-backdrop.is-open { opacity:1; visibility:visible; transition:opacity .22s ease, visibility 0s; }
            .team-switch summary, .team-switch .team-row, .team-switch a.team-row, .logout-btn { min-height:44px; }

            .page-pad { padding:1.25rem 16px 2rem; }
            .page-head { margin-bottom:1.25rem; }
            .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:0.75rem; }
            .kpi-tile { padding:1rem; }
            .list-grid { grid-template-columns:minmax(0,1fr); }
            .list-card { padding:1.25rem; }
            .kanban-scroll { scroll-snap-type:x mandatory; }
            .kanban-scroll > .kanban-col { width:min(18rem, calc(100vw - 4.5rem)); }
            .kanban-move { min-height:44px; font-size:14px; }

            /* Notification panel: pinned to the screen edges instead of a 320px box that runs off the left side */
            .notif-panel { position:fixed !important; top:calc(var(--topbar-h) + 6px) !important; left:10px !important; right:10px !important; width:auto !important; max-height:calc(100vh - var(--topbar-h) - 20px) !important; max-height:calc(100dvh - var(--topbar-h) - 20px) !important; }
        }
        @media (pointer:coarse) { .nav-item { min-height:44px; } }
        @media (prefers-reduced-motion: reduce) {
            .sidebar, .sidebar.is-open, .shell-backdrop, .shell-backdrop.is-open { transition:none !important; }
            .live-dot { animation:none; }
        }
    </style>
    @livewireStyles
    {{-- Alpine is NOT loaded from a CDN here: Livewire 3 ships and starts its own. A second copy (the unpkg
         3.10.x tag that used to sit here) replaced window.Alpine, so every Livewire re-render threw
         "window.Alpine.cloneNode is not a function" and the DOM never updated (the bell never opened). --}}
</head>
<body>
    <div data-shell-page><x-banner /></div>

    {{-- Off-canvas drawer below 860px, plain sidebar from 860px. The inline script at the end of
         <body> drives it (no Alpine: this one has to keep working if a CDN script is slow). --}}
    <aside id="shell-drawer" class="sidebar" aria-label="Dot.Tasks navigation">
        <div class="sidebar-brand">
            <div class="brand-icon" style="background:transparent;border:none;padding:0;">
                <img src="{{ asset('images/mark-outlined.png') }}" alt="Dot.Tasks" style="width:36px;height:36px;border-radius:10px;object-fit:contain;" />
            </div>
            <div>
                <div class="brand-name">Dot.Tasks</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">Task Management</span>
                </div>
            </div>
            <button type="button" class="shell-drawer-close" data-shell-close>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                Close
            </button>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav" aria-label="Primary">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>
            <a href="{{ route('tasks.mine') }}" class="nav-item {{ request()->routeIs('tasks.mine') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">person_check</span>
                My tasks
            </a>
            <a href="{{ route('tasks.today') }}" class="nav-item {{ request()->routeIs('tasks.today') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">today</span>
                Today
            </a>
            <a href="{{ route('tasks.upcoming') }}" class="nav-item {{ request()->routeIs('tasks.upcoming') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">event_upcoming</span>
                Upcoming
            </a>
            <a href="{{ route('labels.index') }}" class="nav-item {{ request()->routeIs('labels.index') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">sell</span>
                Labels
            </a>
            <a href="{{ route('removed.index') }}" class="nav-item {{ request()->routeIs('removed.index') ? 'active' : '' }}"><span class="material-symbols-rounded nav-icon">restore_from_trash</span> Removed</a>
            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
            </div>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
            <details class="team-switch">
                <summary>
                    <span>Team: {{ Auth::user()->currentTeam->name }}</span>
                    <span class="material-symbols-rounded" aria-hidden="true" style="font-size:18px;">unfold_more</span>
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
                                    <span class="team-name">{{ Auth::user()->isCurrentTeam($switchTeam) ? $switchTeam->name.' (current)' : 'Switch to '.$switchTeam->name }}</span>
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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <span>Menu</span>
        </button>
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Tasks
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        <livewire:notification-bell />
        @endauth
        <button
            type="button"
            onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('dot-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');"
            class="topbar-btn"
            title="Toggle dark mode"
            aria-label="Toggle dark mode"
        >
            <span class="material-symbols-rounded">dark_mode</span>
        </button>
        <a href="{{ route('profile.show') }}" class="topbar-btn topbar-profile" title="Profile" aria-label="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
    </header>

    {{-- The stock Jetstream bar (@livewire('navigation-menu')) used to render here. It sat in the flow
         under the fixed sidebar and top bar, adding a phantom 64px above every page and holding the only
         team switcher and Log out, which nobody could click. Those now live in the sidebar footer. --}}
    <div class="content-wrap" data-shell-page>
        <main>{{ $slot }}</main>
    </div>


    <script>
        // Drawer behaviour for the responsive shell (below 860px). Plain JS on purpose.
        (function () {
            var btn = document.getElementById('shell-menu-button');
            var drawer = document.getElementById('shell-drawer');
            var backdrop = document.getElementById('shell-backdrop');
            if (!btn || !drawer || !backdrop) { return; }
            var root = document.documentElement;
            var wide = window.matchMedia('(min-width: 860px)');
            var closeBtn = drawer.querySelector('.shell-drawer-close');
            var pages = Array.prototype.slice.call(document.querySelectorAll('[data-shell-page]'));
            var open = false;

            function setInert(el, on) { if (on) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); } }

            function render() {
                drawer.classList.toggle('is-open', open);
                backdrop.classList.toggle('is-open', open);
                root.classList.toggle('shell-locked', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                pages.forEach(function (el) { setInert(el, open); });
                // Closed on a phone: the drawer must not be focusable or readable. On wide screens it is just the sidebar.
                setInert(drawer, !wide.matches && !open);
            }

            function setOpen(next, returnFocus) {
                if (next && wide.matches) { return; }
                open = next;
                render();
                if (open) {
                    (closeBtn || drawer.querySelector('a')).focus();
                } else if (returnFocus) {
                    btn.focus();
                }
            }

            btn.addEventListener('click', function () { setOpen(!open, false); });
            backdrop.addEventListener('click', function () { setOpen(false, true); });
            if (closeBtn) { closeBtn.addEventListener('click', function () { setOpen(false, true); }); }
            document.addEventListener('keydown', function (e) {
                if (open && (e.key === 'Escape' || e.key === 'Esc')) { e.preventDefault(); setOpen(false, true); }
            });
            // Following a link (or logging out) leaves the page: close without stealing focus back.
            drawer.addEventListener('click', function (e) {
                if (open && e.target.closest && e.target.closest('a[href]')) { setOpen(false, false); }
            });
            var onChange = function () { if (wide.matches) { open = false; } render(); };
            if (wide.addEventListener) { wide.addEventListener('change', onChange); } else { wide.addListener(onChange); }
            // Back/forward cache can restore the page mid-open.
            window.addEventListener('pageshow', function () { open = false; render(); });
            render();
        })();
    </script>

    @stack('modals')
    @livewireScripts
</body>
</html>
