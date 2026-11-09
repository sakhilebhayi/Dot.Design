@props(['kind' => 'neutral'])
<span {{ $attributes->class(['lt-tag', 'lt-tag--'.$kind => $kind !== 'neutral']) }}>{{ $slot }}</span>
