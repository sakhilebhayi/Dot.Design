@props(['value'])

<label {{ $attributes->merge(['class' => 'lt-label']) }}>{{ $value ?? $slot }}</label>
