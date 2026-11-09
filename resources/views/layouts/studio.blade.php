<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="st-html">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'Design') | Dot.Design</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="preload" href="{{ asset('fonts/ui/atkinson-hyperlegible-next-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @vite(['resources/css/studio.css', 'resources/js/studio/main.js'])
</head>
<body class="st-body">
    <a class="lt-skip" href="#st-stage">Skip to the canvas</a>
    @yield('content')
</body>
</html>
