@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', "We'll be right back")
@section('message', 'The platform is briefly down for maintenance. Check back shortly. Nothing you were working on has been lost.')

@section('actions')
    <a href="/" class="lt-btn">Try again</a>
@endsection
