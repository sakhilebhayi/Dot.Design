@props(['text', 'side' => null])
<span data-tip="{{ $text }}" @if($side) data-tip-side="{{ $side }}" @endif {{ $attributes->class(['inline-flex']) }}>{{ $slot }}</span>
