<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="st-html dot-roboto" data-dh-theme-key="dot-design-theme" data-dh-theme-default="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'Design') | Dot.Design</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    @include('partials.ui-head')
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    {{-- The shared Dot UI header (logo, search, Dot Apps, reviews bell, account) sits above the editor, as on every other page. --}}
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-header.css') }}">
    <script src="{{ asset('dot-ui/js/dot-search.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-search-results.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-switcher.js') }}" defer></script>
    <script src="{{ asset('dot-ui/js/dot-nav.js') }}" defer></script>
    @vite(['resources/css/studio.css', 'resources/js/studio/main.js', 'resources/js/ds/header-search.js'])
</head>
<body class="st-body">
    @include('partials.app-header', ['nav' => \App\Support\Ui\Navigation::build(request()), 'barTitle' => '', 'skip' => '#st-stage'])
    @yield('content')
</body>
</html>
