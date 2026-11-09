@props(['color' => null, 'label' => null, 'value' => null, 'large' => false])
<span {{ $attributes->class(['lt-swatch', 'lt-swatch--lg' => $large]) }}>
    <span class="lt-swatch__chip" @if($color) style="--c: {{ preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : 'transparent' }}" @endif aria-hidden="true"></span>
    @if ($label || $value || trim((string) $slot) !== '')
    <span class="lt-swatch__label">{{ $label }}{{ $slot }}@if($value) <span class="lt-swatch__value">{{ $value }}</span>@endif</span>
    @endif
</span>
