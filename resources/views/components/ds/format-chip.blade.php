@props(['name', 'width', 'height', 'size' => null, 'href' => null])
@php($w = max(1, (float) $width)) @php($h = max(1, (float) $height))
<{{ $href ? 'a' : 'span' }} @if($href) href="{{ $href }}" @endif {{ $attributes->class(['lt-fchip']) }}>
    <span class="lt-fchip__frame" style="--w: {{ $w }}; --h: {{ $h }}" aria-hidden="true"></span>
    <span>
        <span class="lt-fchip__name">{{ $name }}</span>
        @if ($size)<span class="lt-fchip__size">{{ $size }}</span>@endif
    </span>
</{{ $href ? 'a' : 'span' }}>
