@php
    $nav = \App\Support\Ui\Navigation::build(request());
    $user = auth()->user();
    $team = $user?->currentTeam;
    // The slot is rendered before this layout, so a page's own heading (x-ds.page-header) has already named the page.
    $pageTitle = isset($header) ? trim(strip_tags((string) $header)) : (string) request()->attributes->get('page_title', '');
    $barTitle = (string) request()->attributes->get('bar_title', '');
    $teamSettings = $nav['team'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-key="dot-design-theme" data-dh-theme-default="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ $pageTitle !== '' ? $pageTitle.' | ' : '' }}Dot.Design</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @include('partials.ui-head', ['header' => true])
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    {{-- Shared Dot UI (header, search, platform switcher), served from this site's own public/dot-ui ('self' only: no inline style or script). Loaded before the app CSS so the host mapping in shell.css wins. --}}
    {{-- dot-ui's stylesheet is linked from partials.ui-head (first stylesheet). One script: this host calls DotSearchResults.attach() (resources/js/ds/header-search.js), so it links the full bundle, not the core one with the lazy results controller. --}}
    <script src="{{ asset('dot-ui/js/dot-ui.min.js') }}" defer></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- No @livewireStyles / @livewireScripts here: Livewire (83 KB with Alpine) is only needed by the Jetstream account and team pages, and it injects its own assets into a page that holds a Livewire component. Every other page was waiting for it before its own script could run. --}}
    @stack('head')
</head>
<body>
    <div class="lt-navbar" aria-hidden="true"><span></span></div>
    <x-banner />

    @include('partials.app-header', ['nav' => $nav, 'barTitle' => $barTitle])

    <div class="lt-shell">
        <aside class="lt-rail" aria-label="Dot.Design">
            @if ($team && $teamSettings)
                <a href="{{ $teamSettings }}" class="lt-workspace__btn" aria-label="Workspace settings: {{ $team->name }}">
                    <x-ds.avatar :name="$team->name" size="sm" />
                    <span class="lt-workspace__name"><b>{{ $team->name }}</b><small>{{ $team->personal_team ? 'Personal workspace' : 'Workspace' }}</small></span>
                </a>
            @endif

            <nav class="lt-nav" aria-label="Main">
                @foreach ($nav['groups'] as $group)
                    @if ($group['key'] !== 'studio')<div class="lt-nav__group">{{ $group['label'] }}</div>@endif
                    @foreach ($group['items'] as $item)
                        <a href="{{ $item['href'] }}" class="lt-nav__item" @if($item['current']) aria-current="page" @endif><x-ds.icon :name="$item['icon']" />{{ $item['label'] }}</a>
                    @endforeach
                @endforeach
            </nav>
        </aside>

        <div class="lt-sheet">
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

    {{-- Phone tab bar: the four destinations used most, and Menu, which opens the shared drawer (everything else, workspace, account, theme). --}}
    <nav class="lt-tabbar" aria-label="Main">
        @foreach ($nav['primary'] as $item)
            <a href="{{ $item['href'] }}" class="lt-tab-item" @if($item['current']) aria-current="page" @endif><x-ds.icon :name="$item['icon']" />{{ $item['label'] }}</a>
        @endforeach
        <button type="button" class="lt-tab-item" data-dh-menu-button aria-controls="dh-drawer" aria-expanded="false" aria-haspopup="dialog"><x-ds.icon name="menu" />More</button>
    </nav>

    <x-ds.toast />
    @stack('modals')
</body>
</html>
