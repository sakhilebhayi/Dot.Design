@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The link may be old, or the design may have been deleted or shared with someone else. Go back and pick up from there.")

@section('actions')
    @auth
        <a href="{{ route('dashboard') }}" class="lt-btn">Back to Home</a>
    @else
        <a href="/" class="lt-btn">Go to the start page</a>
    @endauth
@endsection
