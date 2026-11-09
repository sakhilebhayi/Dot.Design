@props(['value' => null, 'label' => 'Progress'])
@if ($value === null)
<div {{ $attributes->class(['lt-progress', 'lt-progress--busy']) }} role="progressbar" aria-label="{{ $label }}" aria-busy="true"><span></span></div>
@else
<div {{ $attributes->class(['lt-progress']) }} role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $value }}"><span style="--p: {{ max(0, min(100, (float) $value)) / 100 }}"></span></div>
@endif
