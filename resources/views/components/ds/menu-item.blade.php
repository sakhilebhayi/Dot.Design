@props(['href' => null, 'icon' => null, 'current' => false, 'type' => 'button'])
@if ($href)
<a href="{{ $href }}" role="menuitem" {{ $attributes->class(['lt-menu__item', 'is-current' => $current]) }}>@if($icon)<x-ds.icon :name="$icon" />@endif<span>{{ $slot }}</span></a>
@else
<button type="{{ $type }}" role="menuitem" {{ $attributes->class(['lt-menu__item', 'is-current' => $current]) }}>@if($icon)<x-ds.icon :name="$icon" />@endif<span>{{ $slot }}</span></button>
@endif
