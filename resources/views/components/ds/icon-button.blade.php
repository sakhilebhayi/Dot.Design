@props(['icon', 'label', 'href' => null, 'type' => 'button', 'tip' => true, 'side' => null, 'outline' => false])
@php($classes = ['lt-icon-btn', 'lt-icon-btn--outline' => $outline])
@if ($href)
<a href="{{ $href }}" aria-label="{{ $label }}" @if($tip) data-tip="{{ $label }}" @endif @if($side) data-tip-side="{{ $side }}" @endif {{ $attributes->class($classes) }}><x-ds.icon :name="$icon" /></a>
@else
<button type="{{ $type }}" aria-label="{{ $label }}" @if($tip) data-tip="{{ $label }}" @endif @if($side) data-tip-side="{{ $side }}" @endif {{ $attributes->class($classes) }}><x-ds.icon :name="$icon" /></button>
@endif
