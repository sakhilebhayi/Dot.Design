<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Design</title>

    <!-- Favicons -->
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } }</script>
    <script>
        // Apply the saved theme before first paint to avoid a flash of the wrong theme.
        (function () {
            var saved = localStorage.getItem('dot-design-theme');
            if (saved === 'light') document.documentElement.classList.add('light');
        })();
    </script>
    <script>
        // Shell state: theme + the mobile drawer. Defined before Alpine starts (Livewire
        // boots Alpine at the end of <body>). The drawer only exists below 860px; at or
        // above it the sidebar is permanent and this state is ignored.
        function dotShell() {
            return {
                theme: (function () { try { return localStorage.getItem('dot-design-theme') || 'dark'; } catch (e) { return 'dark'; } })(),
                navOpen: false,
                init() {
                    var wide = window.matchMedia('(min-width: 860px)');
                    var onChange = (e) => { if (e.matches) this.closeNav(false); };
                    if (wide.addEventListener) wide.addEventListener('change', onChange); else wide.addListener(onChange);
                },
                openNav() {
                    this.navOpen = true;
                    this.$nextTick(() => { var c = document.getElementById('shell-drawer-close'); if (c) c.focus(); });
                },
                closeNav(restoreFocus) {
                    if (! this.navOpen) return;
                    this.navOpen = false;
                    if (restoreFocus !== false) {
                        this.$nextTick(() => { var b = document.getElementById('shell-menu-button'); if (b) b.focus(); });
                    }
                },
            };
        }
    </script>
    <style>
        :root {
            --accent: #ec4899; --accent-rgb: 236,72,153;
            --bg: #09090b; --bg-raised: #0d0d10; --bg-card: #141416; --bg-input: rgba(255,255,255,0.04);
            --text: #f4f4f5; --text-muted: #a1a1aa; --text-dim: #71717a; --text-faint: #3f3f46;
            --border: rgba(255,255,255,0.07); --border-strong: rgba(255,255,255,0.11);
            --topbar-bg: rgba(9,9,11,0.85);
            --topbar-h: 54px; --sidebar-w: 260px;
        }
        html.light {
            --bg: #f7f7f8; --bg-raised: #ffffff; --bg-card: #ffffff; --bg-input: rgba(0,0,0,0.03);
            --text: #18181b; --text-muted: #52525b; --text-dim: #71717a; --text-faint: #a1a1aa;
            --border: rgba(0,0,0,0.08); --border-strong: rgba(0,0,0,0.14);
            --topbar-bg: rgba(255,255,255,0.85);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; transition:background .15s,color .15s; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }

        /* Sidebar */
        .sidebar { position:fixed; left:0; top:0; width:var(--sidebar-w); height:100vh; height:100dvh; background:var(--bg-raised); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(236,72,153,0.1) 0%, transparent 65%); pointer-events:none; }

        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        .brand-icon { width:36px; height:36px; border-radius:10px; overflow:hidden; flex-shrink:0; }
        .brand-icon img { width:100%; height:100%; object-fit:cover; }
        .brand-name { font-family:'Syne',sans-serif; font-size:14.5px; font-weight:700; color:var(--text); letter-spacing:-0.01em; line-height:1.2; }
        .brand-status { display:flex; align-items:center; gap:5px; margin-top:3px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#ec4899; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(236,72,153,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(236,72,153,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:var(--text-faint); text-transform:uppercase; letter-spacing:0.09em; }

        .sidebar-divider { height:1px; background:var(--border); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:var(--text-faint); text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:var(--text-dim); text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:rgba(127,127,127,0.08); color:var(--text-muted); transform:translateX(1px); }
        .nav-item.active { background:rgba(236,72,153,0.1); color:#ec4899; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }

        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid var(--border); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(236,72,153,0.18); border:1px solid rgba(236,72,153,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#ec4899; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:var(--text-dim); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Topbar */
        .topbar { position:fixed; top:0; left:var(--sidebar-w); right:0; height:var(--topbar-h); background:var(--topbar-bg); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:var(--text); flex:1; min-width:0; }
        .topbar-title { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .topbar-title > * { margin:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .topbar-team { font-size:11px; color:var(--text-dim); background:rgba(127,127,127,0.08); border:1px solid var(--border); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid var(--border); background:var(--bg-input); display:flex; align-items:center; justify-content:center; color:var(--text-dim); cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(127,127,127,0.12); color:var(--text-muted); }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* User menu */
        .user-menu { position:relative; }
        .user-menu-panel { position:absolute; top:40px; right:0; width:230px; background:var(--bg-card); border:1px solid var(--border); border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,0.45); padding:6px; z-index:50; }
        .user-menu-label { font-size:10px; font-weight:600; color:var(--text-faint); text-transform:uppercase; letter-spacing:0.08em; padding:8px 10px 4px; }
        .user-menu-item { display:flex; align-items:center; gap:8px; width:100%; padding:7px 10px; border-radius:7px; font-size:13px; color:var(--text-muted); text-decoration:none; background:none; border:none; cursor:pointer; text-align:left; font-family:'Inter',sans-serif; }
        .user-menu-item:hover { background:rgba(127,127,127,0.1); color:var(--text); }
        .user-menu-item.current { color:#ec4899; }
        .user-menu-item .material-symbols-rounded { font-size:16px; flex-shrink:0; }
        .user-menu-divider { height:1px; background:var(--border); margin:5px 4px; }

        /* Content */
        .content-wrap { margin-left:var(--sidebar-w); padding-top:var(--topbar-h); min-height:100vh; min-width:0; }

        /* Shared UI tokens */
        .dot-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; }
        .dot-card:hover { border-color:var(--border-strong); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; }
        .dot-input { background:var(--bg-input); border:1px solid var(--border); border-radius:8px; color:var(--text); font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(236,72,153,0.45); box-shadow:0 0 0 3px rgba(236,72,153,0.07); }
        .dot-input::placeholder { color:var(--text-faint); }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#ec4899; color:#09090b; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:rgba(127,127,127,0.08); color:var(--text-muted); border:1px solid var(--border); }
        .dot-btn-ghost:hover { background:rgba(127,127,127,0.14); color:var(--text); }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(236,72,153,0.12); color:#ec4899; }
        select.dot-input option { background:var(--bg-card); color:var(--text); }
        .skeleton { background:linear-gradient(90deg, var(--bg-input) 25%, var(--border) 37%, var(--bg-input) 63%); background-size:400% 100%; animation:skeleton-loading 1.4s ease infinite; border-radius:8px; }
        @keyframes skeleton-loading { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }

        /* ------------------------------------------------------------------
           Responsive shell. One breakpoint: below 860px the sidebar becomes an
           off-canvas drawer opened from the top bar (see dotShell() in the head).
           Use max-width:859.98px / min-width:860px consistently.
           Hooks: #shell-menu-button, #shell-drawer, #shell-backdrop, .topbar,
           .table-scroll (wrap any wide table), .page (non-editor page body).
           ------------------------------------------------------------------ */
        .shell-menu-button, .shell-drawer-close, .shell-backdrop { display:none; }

        /* Page body for the non-editor pages (the editor keeps its own .ed-root padding). */
        .page { padding:2rem 2.5rem; max-width:1400px; }
        .page--narrow { max-width:640px; }
        .page-head { display:flex; align-items:center; justify-content:space-between; gap:0.75rem 1rem; flex-wrap:wrap; margin-bottom:2rem; }
        .page-head > div { min-width:0; }
        .page h1 { overflow-wrap:anywhere; }
        .card-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fill, minmax(min(var(--card-min, 260px), 100%), 1fr)); }
        .kpi-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit, minmax(10rem, 1fr)); margin-bottom:2.25rem; }
        .form-grid-3 { display:grid; gap:1rem; grid-template-columns:repeat(3, minmax(0, 1fr)); }
        .table-scroll { overflow-x:auto; max-width:100%; -webkit-overflow-scrolling:touch; }
        .table-scroll > table { min-width:34rem; }
        .table-scroll:focus-visible { outline:2px solid #ec4899; outline-offset:2px; }

        @media (max-width: 859.98px) {
            :root { --topbar-h: 56px; }

            /* Drawer: closed = invisible + unfocusable (visibility), not just moved off-screen. */
            .sidebar { width:min(280px, 86vw); transform:translateX(-100%); visibility:hidden; transition:transform .22s ease, visibility 0s linear .22s; z-index:50; box-shadow:none; overscroll-behavior:contain; }
            .sidebar.is-open { transform:none; visibility:visible; transition:transform .22s ease, visibility 0s; box-shadow:0 0 40px rgba(0,0,0,0.5); }
            .shell-backdrop { display:block; position:fixed; inset:0; z-index:45; background:rgba(0,0,0,0.6); opacity:0; visibility:hidden; transition:opacity .22s ease, visibility 0s linear .22s; }
            .shell-backdrop.is-open { opacity:1; visibility:visible; transition:opacity .22s ease, visibility 0s; }
            body.shell-locked { overflow:hidden; }

            .topbar { left:0; padding:0 12px; gap:8px; }
            .content-wrap { margin-left:0; }

            .shell-menu-button { display:inline-flex; align-items:center; gap:6px; min-height:44px; padding:0 12px 0 8px; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:var(--text); font-family:'Inter',sans-serif; font-size:13px; font-weight:600; cursor:pointer; flex-shrink:0; }
            .shell-menu-button .material-symbols-rounded { font-size:22px; }
            .shell-drawer-close { display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; margin-left:auto; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:var(--text-muted); cursor:pointer; flex-shrink:0; }
            .shell-menu-button:focus-visible, .shell-drawer-close:focus-visible, .nav-item:focus-visible, .topbar-btn:focus-visible { outline:2px solid #ec4899; outline-offset:2px; }

            .sidebar-brand { padding-right:12px; }
            .nav-item { min-height:44px; padding:10px 12px; }
            .nav-item:hover { transform:none; }

            .topbar-btn { width:44px; height:44px; border-radius:8px; }
            .topbar-btn .material-symbols-rounded { font-size:20px; }
            .user-menu-panel { top:calc(100% + 6px); width:min(260px, calc(100vw - 24px)); max-height:calc(100dvh - var(--topbar-h) - 12px); overflow-y:auto; }
            .user-menu-item { min-height:44px; }

            .page { padding:1.5rem 1.25rem 2rem; }
            .page .dot-btn { min-height:44px; justify-content:center; }
            .page .dot-input { min-height:44px; font-size:16px; }
        }

        @media (max-width: 559.98px) {
            .topbar-team { display:none; }
            .page { padding:1.25rem 1rem 2rem; }
            .page-head { margin-bottom:1.5rem; }
            .kpi-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:0.75rem; }
            .kpi-grid > :last-child:nth-child(odd) { grid-column:1 / -1; }
            .form-grid-3 { grid-template-columns:minmax(0, 1fr); }
        }

        @media (prefers-reduced-motion: reduce) {
            .sidebar, .sidebar.is-open, .shell-backdrop, .shell-backdrop.is-open { transition:none; }
            .live-dot, .skeleton { animation:none; }
            .nav-item:hover { transform:none; }
        }
    </style>
    @livewireStyles
    {{--
        Livewire 3's @livewireScripts (in <body> below) already bundles and
        auto-starts Alpine -- a manual Alpine CDN <script> tag here used to
        load a SECOND, independent Alpine instance alongside it. Existing
        usages (the theme toggle, user-menu dropdown) were small enough that
        the resulting "Detected multiple instances of Alpine running" race
        never visibly broke anything, but it silently corrupts x-data scope
        for anything non-trivial: the canvas editor's x-for/x-model/:href
        bindings were intermittently failing (x-currentIfEl errors,
        "exportUrlTemplate is not defined") depending on which instance won
        the race. Removed rather than kept both, per Alpine/Livewire's own
        guidance to never load Alpine yourself when Livewire is present.
    --}}
</head>
<body x-data="dotShell()" x-init="$watch('theme', value => { document.documentElement.classList.toggle('light', value === 'light'); try { localStorage.setItem('dot-design-theme', value); } catch (e) {} })" :class="{ 'shell-locked': navOpen }" @keydown.escape.window="closeNav()">
    <x-banner />

    {{-- Mobile drawer backdrop: click closes the drawer. Hidden at >= 860px. --}}
    <div id="shell-backdrop" class="shell-backdrop" :class="{ 'is-open': navOpen }" aria-hidden="true" @click="closeNav()"></div>

    <aside id="shell-drawer" class="sidebar" aria-label="Dot.Design sidebar" :class="{ 'is-open': navOpen }" @keydown.stop @keydown.escape="closeNav()">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <img src="{{ asset('images/logo.png') }}" alt="Dot.Design">
            </div>
            <div>
                <div class="brand-name">Dot.Design</div>
                <div class="brand-status">
                    <div class="live-dot"></div>
                    <span class="brand-subtitle">Design Studio</span>
                </div>
            </div>
            <button type="button" id="shell-drawer-close" class="shell-drawer-close" aria-label="Close menu" @click="closeNav()">
                <span class="material-symbols-rounded" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav" aria-label="Main" @click="if ($event.target.closest('a')) closeNav(false)">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>

            <div class="nav-section-label">Design Studio</div>
            <a href="{{ route('design.projects.index') }}" class="nav-item {{ request()->routeIs('design.projects.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">brush</span>
                Projects
            </a>

            <div class="nav-section-label">Design System</div>
            <a href="{{ route('design-system.token-sets.index') }}" class="nav-item {{ request()->routeIs('design-system.token-sets.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">palette</span>
                Token Sets
            </a>
            <a href="{{ route('design-system.components.index') }}" class="nav-item {{ request()->routeIs('design-system.components.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">widgets</span>
                Components
            </a>

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
        </div>
        @endauth
    </aside>

    <header class="topbar" :inert="navOpen">
        <button type="button" id="shell-menu-button" class="shell-menu-button" aria-expanded="false" aria-controls="shell-drawer" :aria-expanded="navOpen ? 'true' : 'false'" @click="navOpen ? closeNav() : openNav()">
            <span class="material-symbols-rounded" aria-hidden="true">menu</span>
            <span>Menu</span>
        </button>
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Design
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        @endauth
        <button type="button" class="topbar-btn" title="Toggle light / dark theme" aria-label="Toggle light / dark theme" @click="theme = (theme === 'light' ? 'dark' : 'light')">
            <span class="material-symbols-rounded" x-text="theme === 'light' ? 'dark_mode' : 'light_mode'"></span>
        </button>
        @auth
        <div class="user-menu" x-data="{ open: false }" @click.away="open = false">
            <button type="button" class="topbar-btn" title="Account" aria-label="Account menu" aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'" @click="open = ! open" @keydown.escape="open = false">
                <span class="material-symbols-rounded">account_circle</span>
            </button>

            <div class="user-menu-panel" x-show="open" x-cloak x-transition style="display:none;">
                <div class="user-menu-label">{{ __('Account') }}</div>
                <a href="{{ route('profile.show') }}" class="user-menu-item">
                    <span class="material-symbols-rounded">manage_accounts</span>
                    {{ __('Profile & Settings') }}
                </a>

                @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
                    <div class="user-menu-divider"></div>
                    <div class="user-menu-label">{{ __('Manage Team') }}</div>
                    {{-- Auth::user()->currentTeam can genuinely be null (see the guard note in
                         resources/views/navigation-menu.blade.php) -- only link to team settings
                         when a team actually resolved; don't hide "Create New Team" behind it,
                         since that's precisely the escape hatch a teamless user needs. --}}
                    @if (Auth::user()->currentTeam)
                        <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="user-menu-item">
                            <span class="material-symbols-rounded">groups</span>
                            {{ __('Team Settings') }}
                        </a>
                    @endif
                    @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                        <a href="{{ route('teams.create') }}" class="user-menu-item">
                            <span class="material-symbols-rounded">add_circle</span>
                            {{ __('Create New Team') }}
                        </a>
                    @endcan

                    @if (Auth::user()->allTeams()->count() > 1)
                        <div class="user-menu-divider"></div>
                        <div class="user-menu-label">{{ __('Switch Teams') }}</div>
                        @foreach (Auth::user()->allTeams() as $team)
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @method('PUT')
                                @csrf
                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                {{-- Auth::user()->isCurrentTeam($team) dereferences currentTeam->id
                                     internally with no null check, so compare against the
                                     null-safe id directly instead of calling it. --}}
                                <button type="submit" class="user-menu-item {{ $team->id === Auth::user()->currentTeam?->id ? 'current' : '' }}">
                                    <span class="material-symbols-rounded">{{ $team->id === Auth::user()->currentTeam?->id ? 'check_circle' : 'radio_button_unchecked' }}</span>
                                    {{ $team->name }}
                                </button>
                            </form>
                        @endforeach
                    @endif
                @endif

                <div class="user-menu-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="user-menu-item">
                        <span class="material-symbols-rounded">logout</span>
                        {{ __('Log Out') }}
                    </button>
                </form>
            </div>
        </div>
        @endauth
    </header>

    <div class="content-wrap" :inert="navOpen">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
