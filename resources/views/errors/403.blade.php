@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "It may belong to a different workspace, need a role you do not have, or require you to log in first. If you think this is a mistake, ask whoever owns the workspace.")

@section('actions')
    <a href="/" class="lt-btn">Go home</a>
    @auth
        <a href="{{ route('dashboard') }}" class="lt-btn lt-btn--ghost">Go to Home</a>
    @else
        @if (Route::has('login'))<a href="{{ route('login') }}" class="lt-btn lt-btn--ghost">Log in</a>@endif
    @endauth
@endsection
