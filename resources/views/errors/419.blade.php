@extends('errors._ecosystem-layout', ['code' => 419])

@section('title', 'Your session timed out')
@section('message', 'For your security, a form expires after a while without activity. Nothing was lost. Go back and send it again.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="lt-btn">Go back and retry</a>
    <a href="/" class="lt-btn lt-btn--ghost">Go home</a>
@endsection
