@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "It may belong to another workspace or need a role you do not have. Ask whoever owns it to share it with you, or go back to your own work.")

@section('actions')
    @auth
        <a href="{{ route('dashboard') }}" class="lt-btn">Back to Home</a>
    @else
        <a href="/" class="lt-btn">Go to the start page</a>
    @endauth
@endsection
