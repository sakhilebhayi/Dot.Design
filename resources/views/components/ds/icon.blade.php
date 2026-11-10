@props(['name', 'size' => null, 'label' => null])
@php($shapes = \App\Support\Ui\Icons::path($name) ?? \App\Support\Ui\Icons::path('circle-dot'))
<svg {{ $attributes->class(['lt-icon', 'lt-icon--'.$size => $size]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>{!! $shapes !!}</svg>
