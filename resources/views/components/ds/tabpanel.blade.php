@props(['id', 'selected' => false, 'prefix' => 'tab'])
<div role="tabpanel" id="{{ $prefix }}-panel-{{ $id }}" aria-labelledby="{{ $prefix }}-{{ $id }}" tabindex="0" @unless($selected) hidden @endunless {{ $attributes->class(['lt-tabpanel']) }}>{{ $slot }}</div>
