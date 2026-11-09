@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', 'Slow down a little')
@section('message', 'Too many requests came in a short time. This protects everyone using the platform. Wait a minute and try again.')

@section('actions')
    <a href="/" class="lt-btn">Go home</a>
@endsection
