{{-- R-61: InfoDot has switched Dot.Design off (maintenance or unavailable). Plain 503: it says so and reads nothing of the
     company's designs; everything already here is kept exactly as it is. Not a purchase or permission problem. --}}
@extends('errors._ecosystem-layout', ['code' => 503, 'hideNext' => true])

@section('title', 'Dot.Design is not available right now')
@section('message', ($message ?? 'Dot.Design is temporarily unavailable.').' Everything recorded here is kept as it is.')

@section('actions')
    <a href="{{ url()->current() }}" class="lt-btn">Try again</a>
@endsection
