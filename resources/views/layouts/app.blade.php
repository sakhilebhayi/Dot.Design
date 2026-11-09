@php
    $nav = \App\Support\Ui\Navigation::build(request());
    $user = auth()->user();
    $team = $user?->currentTeam;
    // The slot is rendered before this layout, so a page's own heading (x-ds.page-header) has already named the page.
    $pageTitle = isset($header) ? trim(strip_tags((string) $header)) : (string) request()->attributes->get('page_title', '');
    $hasTeams = $user && \Laravel\Jetstream\Jetstream::hasTeamFeatures();
    $tokenRoute = \Illuminate\Support\Facades\Route::has('api-tokens.index') ? route('api-tokens.index') : null;
    $isAdmin = $user && $team && \Illuminate\Support\Facades\Route::has('design.activity.page') && in_array(\App\Support\Design\DesignAccess::teamRole($user->id, $team->id), ['owner', 'admin'], true);
    $settingsRoute = \Illuminate\Support\Facades\Route::has('design.settings') ? route('design.settings') : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ $pageTitle !== '' ? $pageTitle.' | ' : '' }}Dot.Design</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="{{ asset('fonts/ui/atkinson-hyperlegible-next-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- No @livewireStyles / @livewireScripts here: Livewire (83 KB with Alpine) is only needed by the Jetstream account and team pages, and it injects its own assets into a page that holds a Livewire component. Every other page was waiting for it before its own script could run. --}}
    @stack('head')
</head>
<body>
    <a class="lt-skip" href="#main">Skip to content</a>
    <div class="lt-navbar" aria-hidden="true"><span></span></div>
    <x-banner />

    <div class="lt-shell">
        <aside class="lt-rail" aria-label="Dot.Design">
            <a href="{{ route('dashboard') }}" class="lt-brand" aria-label="Dot.Design, home">
                <span class="lt-mark" role="img" aria-label="Dot.Design mark"></span>
                <span class="lt-brand__name">Dot.Design</span>
            </a>

            @if ($hasTeams && $team)
            <div class="lt-workspace">
                <x-ds.menu align="left" side="down" label="Workspace" trigger-class="lt-workspace__btn" trigger-label="Workspace: {{ $team->name }}">
                    <x-slot name="trigger">
                        <x-ds.avatar :name="$team->name" size="sm" />
                        <span class="lt-workspace__name"><b>{{ $team->name }}</b><small>{{ $team->personal_team ? 'Personal workspace' : 'Workspace' }}</small></span>
                        <x-ds.icon name="chevron-down" size="sm" />
                    </x-slot>
                    <div class="lt-menu__label">Switch workspace</div>
                    @foreach ($user->allTeams() as $other)
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @method('PUT') @csrf
                            <input type="hidden" name="team_id" value="{{ $other->id }}">
                            <x-ds.menu-item type="submit" :icon="$other->id === $team->id ? 'check' : null" :current="$other->id === $team->id">{{ $other->name }}</x-ds.menu-item>
                        </form>
                    @endforeach
                    <div class="lt-menu__sep"></div>
                    @if ($nav['team'])<x-ds.menu-item :href="$nav['team']" icon="settings">Workspace settings</x-ds.menu-item>@endif
                    @if ($isAdmin)<x-ds.menu-item :href="route('design.activity.page', ['team_id' => $team->id])" icon="clock">Activity</x-ds.menu-item>@endif
                    @can('create', \Laravel\Jetstream\Jetstream::newTeamModel())
                        <x-ds.menu-item :href="route('teams.create')" icon="plus">Create workspace</x-ds.menu-item>
                    @endcan
                </x-ds.menu>
            </div>
            @endif

            <nav class="lt-nav" aria-label="Main">
                @foreach ($nav['groups'] as $group)
                    @if ($group['key'] !== 'studio')<div class="lt-nav__group">{{ $group['label'] }}</div>@endif
                    @foreach ($group['items'] as $item)
                        <a href="{{ $item['href'] }}" class="lt-nav__item" @if($item['current']) aria-current="page" @endif><x-ds.icon :name="$item['icon']" />{{ $item['label'] }}</a>
                    @endforeach
                @endforeach
            </nav>

            <div class="lt-rail__foot">
                <div class="lt-theme">
                    <span id="theme-label">Theme</span>
                    <div class="lt-seg" role="group" aria-labelledby="theme-label">
                        <button type="button" data-theme-set="light" aria-pressed="false" aria-label="Light" data-tip="Light"><x-ds.icon name="sun" size="sm" /></button>
                        <button type="button" data-theme-set="system" aria-pressed="false" aria-label="Match system" data-tip="System"><x-ds.icon name="monitor" size="sm" /></button>
                        <button type="button" data-theme-set="dark" aria-pressed="false" aria-label="Dark" data-tip="Dark"><x-ds.icon name="moon" size="sm" /></button>
                    </div>
                </div>
                @if ($user)
                <x-ds.menu align="left" side="up" label="Account" trigger-class="lt-user" trigger-label="Account menu for {{ $user->name }}">
                    <x-slot name="trigger">
                        <x-ds.avatar :name="$user->name" :src="$user->profile_photo_path ? $user->profile_photo_url : null" size="sm" />
                        <span class="lt-user__name">{{ $user->name }}</span>
                        <x-ds.icon name="chevron-up" size="sm" />
                    </x-slot>
                    @if ($settingsRoute)<x-ds.menu-item :href="$settingsRoute" icon="settings">Studio settings</x-ds.menu-item>@endif
                    @if ($nav['profile'])<x-ds.menu-item :href="$nav['profile']" icon="user">Profile and security</x-ds.menu-item>@endif
                    @if ($tokenRoute && \Laravel\Jetstream\Jetstream::hasApiFeatures())<x-ds.menu-item :href="$tokenRoute" icon="key">API tokens</x-ds.menu-item>@endif
                    <div class="lt-menu__sep"></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<x-ds.menu-item type="submit" icon="logout">Log out</x-ds.menu-item></form>
                </x-ds.menu>
                @endif
            </div>
        </aside>

        <div class="lt-sheet">
            <header class="lt-topbar">
                <a href="{{ route('dashboard') }}" class="lt-brand" aria-label="Dot.Design, home">
                    <span class="lt-mark" role="img" aria-label="Dot.Design mark"></span>
                    <span class="lt-brand__name">Dot.Design</span>
                </a>
                <div class="lt-topbar__end">
                    <button type="button" class="lt-icon-btn" data-theme-toggle aria-pressed="false" aria-label="Switch theme">
                        <span data-theme-icon="light"><x-ds.icon name="sun" /></span>
                        <span data-theme-icon="dark" hidden><x-ds.icon name="moon" /></span>
                    </button>
                </div>
            </header>

            <main id="main" class="lt-main" tabindex="-1">
                @isset($header)
                    <div class="lt-page">
                        <x-ds.page-header :title="$pageTitle" />
                        {{ $slot }}
                    </div>
                @else
                    {{ $slot }}
                @endisset
            </main>
        </div>
    </div>

    <nav class="lt-tabbar" aria-label="Main">
        @foreach ($nav['primary'] as $item)
            <a href="{{ $item['href'] }}" class="lt-tab-item" @if($item['current']) aria-current="page" @endif><x-ds.icon :name="$item['icon']" />{{ $item['label'] }}</a>
        @endforeach
        <button type="button" class="lt-tab-item" data-lt-dialog-open="more-sheet" aria-haspopup="dialog"><x-ds.icon name="menu" />More</button>
    </nav>

    <x-ds.dialog id="more-sheet" title="More">
        <div class="lt-sheetnav">
            @foreach ($nav['secondary'] as $item)
                <a href="{{ $item['href'] }}" class="lt-menu__item" @if($item['current']) aria-current="page" @endif><x-ds.icon :name="$item['icon']" />{{ $item['label'] }}</a>
            @endforeach
            @if ($nav['team'])<a href="{{ $nav['team'] }}" class="lt-menu__item"><x-ds.icon name="settings" />Workspace settings</a>@endif
            @if ($isAdmin)<a href="{{ route('design.activity.page', ['team_id' => $team->id]) }}" class="lt-menu__item"><x-ds.icon name="clock" />Activity</a>@endif
            @if ($settingsRoute)<a href="{{ $settingsRoute }}" class="lt-menu__item"><x-ds.icon name="settings" />Studio settings</a>@endif
            @if ($nav['profile'])<a href="{{ $nav['profile'] }}" class="lt-menu__item"><x-ds.icon name="user" />Profile and security</a>@endif
            @if ($tokenRoute && \Laravel\Jetstream\Jetstream::hasApiFeatures())<a href="{{ $tokenRoute }}" class="lt-menu__item"><x-ds.icon name="key" />API tokens</a>@endif
            <div class="lt-menu__sep"></div>
            <div class="lt-theme" style="padding: 0.25rem 0.75rem;">
                <span>Theme</span>
                <div class="lt-seg" role="group" aria-label="Theme">
                    <button type="button" data-theme-set="light" aria-pressed="false" aria-label="Light"><x-ds.icon name="sun" size="sm" /></button>
                    <button type="button" data-theme-set="system" aria-pressed="false" aria-label="Match system"><x-ds.icon name="monitor" size="sm" /></button>
                    <button type="button" data-theme-set="dark" aria-pressed="false" aria-label="Dark"><x-ds.icon name="moon" size="sm" /></button>
                </div>
            </div>
            @if ($user)
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="lt-menu__item"><x-ds.icon name="logout" />Log out</button></form>
            @endif
        </div>
    </x-ds.dialog>

    <x-ds.toast />
    @stack('modals')
</body>
</html>
