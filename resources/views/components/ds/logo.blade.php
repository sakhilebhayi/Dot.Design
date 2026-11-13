@props(['alt' => '', 'width' => 1181, 'height' => 886, 'loading' => 'lazy'])
{{-- The full Dot.Design logo in the right colours for the theme: the dark lettering on light surfaces, the white lettering variant on dark ones.
     Both are in the page and CSS shows one, so a stored theme choice and the system setting both work without script. --}}
<span {{ $attributes->class(['lt-logo']) }}>
    <img class="lt-logo__light" src="{{ asset('images/logo.png') }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" loading="{{ $loading }}" decoding="async">
    <img class="lt-logo__dark" src="{{ asset('images/logo-light.png') }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" loading="{{ $loading }}" decoding="async">
</span>
