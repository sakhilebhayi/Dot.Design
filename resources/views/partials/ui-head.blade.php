{{-- Roboto and the shared type scale (dot-ui 0.6.1). Self-hosted, same origin, no inline style or script: the strict CSP allows it. Load this
     before the app's own CSS so the --dot-* tokens exist when the Light Table tokens alias them. Put class="dot-roboto" on <html>.
     A page with the shared header passes header=true and gets the one minified stylesheet that holds Roboto, the type scale, tokens, search,
     switcher and header; every other page (guest, share, errors) loads only the two small minified files it needs. --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
@if (! empty($header))
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
@else
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">
@endif
