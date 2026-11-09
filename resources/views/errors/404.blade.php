@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The page may have moved, been renamed, or never existed. Check the link, or start again from home.")

@section('actions')
    <a href="/" class="lt-btn">Go home</a>
    @auth
        <a href="{{ route('dashboard') }}" class="lt-btn lt-btn--ghost">Go to Home</a>
    @else
        @if (Route::has('login'))<a href="{{ route('login') }}" class="lt-btn lt-btn--ghost">Log in</a>@endif
    @endauth
@endsection
