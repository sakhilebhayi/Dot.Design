@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', 'Slow down a little')
@section('message', 'You sent a lot of requests in a short time. This keeps things quick for everyone. Wait a minute, then try again.')

@section('actions')
    <a href="{{ url()->current() }}" class="lt-btn">Try again</a>
@endsection
