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
    @include('partials.ui-head', ['header' => true])
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    {{-- The shared Dot UI header (logo, search, Dot Apps, reviews bell, account) sits above the editor, as on every other page. --}}
    {{-- dot-ui's stylesheet is linked from partials.ui-head (first stylesheet). One script: this host calls DotSearchResults.attach() (resources/js/ds/header-search.js), so it links the full bundle, not the core one with the lazy results controller. --}}
    <script src="{{ asset('dot-ui/js/dot-ui.min.js') }}" defer></script>
    @vite(['resources/css/studio.css', 'resources/js/studio/main.js', 'resources/js/ds/header-search.js'])
</head>
<body class="st-body">
    @include('partials.app-header', ['nav' => \App\Support\Ui\Navigation::build(request()), 'barTitle' => '', 'skip' => '#st-stage'])
    @yield('content')
</body>
</html>
