@extends('errors._ecosystem-layout', ['code' => 500])

@section('title', 'Something went wrong on our end')
@section('message', "That one is on us, not you. An unexpected error interrupted this page. Try again in a moment, and if it keeps happening, tell us.")

@section('actions')
    <a href="/" class="lt-btn">Go home</a>
    @if (Route::has('contact'))<a href="{{ route('contact') }}" class="lt-btn lt-btn--ghost">Contact support</a>@endif
@endsection
