@props(['name' => '', 'src' => null, 'size' => null])
@php($initial = mb_strtoupper(mb_substr(trim((string) $name) ?: '?', 0, 1)))
@php($hue = (crc32((string) $name) % 5) + 1)
<span {{ $attributes->class(['lt-avatar', 'lt-avatar--'.$size => (bool) $size]) }} data-hue="{{ $hue }}" @if($name) title="{{ $name }}" @endif>@if($src)<img src="{{ $src }}" alt="">@else<span aria-hidden="true">{{ $initial }}</span>@endif</span>
