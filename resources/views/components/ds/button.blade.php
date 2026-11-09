@props(['variant' => 'primary', 'size' => null, 'href' => null, 'icon' => null, 'iconEnd' => null, 'type' => 'button', 'block' => false])
@php
    $classes = ['lt-btn', 'lt-btn--'.$variant => $variant !== 'primary', 'lt-btn--'.$size => (bool) $size, 'lt-btn--block' => $block];
@endphp
@if ($href)
<a href="{{ $href }}" {{ $attributes->class($classes) }}>@if($icon)<x-ds.icon :name="$icon" />@endif{{ $slot }}@if($iconEnd)<x-ds.icon :name="$iconEnd" />@endif</a>
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }}>@if($icon)<x-ds.icon :name="$icon" />@endif{{ $slot }}@if($iconEnd)<x-ds.icon :name="$iconEnd" />@endif</button>
@endif
