<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name', 'Dot.Design') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @include('partials.ui-head')
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @stack('head')
</head>
<body>
    <a class="lt-skip" href="#main">Skip to content</a>
    <div class="lt-navbar" aria-hidden="true"><span></span></div>
    {{ $slot }}
    <x-ds.toast />
    @livewireScripts
</body>
</html>
