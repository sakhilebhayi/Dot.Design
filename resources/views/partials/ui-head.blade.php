{{-- Roboto and the shared type scale (dot-ui). Self-hosted, same origin, no inline style or script: the strict CSP allows it. Load this
     before the app's own CSS so the --dot-* tokens exist when the Light Table tokens alias them. Put class="dot-roboto" on <html>. --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.css') }}">
