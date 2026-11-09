@props(['href' => null, 'pressed' => null, 'icon' => null, 'current' => false])
@if ($href)
<a href="{{ $href }}" @if($current) aria-current="true" @endif {{ $attributes->class(['lt-chip']) }}>@if($icon)<x-ds.icon :name="$icon" />@endif{{ $slot }}</a>
@else
<button type="button" @if($pressed !== null) aria-pressed="{{ $pressed ? 'true' : 'false' }}" @endif {{ $attributes->class(['lt-chip']) }}>@if($icon)<x-ds.icon :name="$icon" />@endif{{ $slot }}</button>
@endif
