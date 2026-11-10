@props(['kind' => 'neutral', 'icon' => null])
@php($icon = $icon ?? ['ok' => 'circle-check', 'warn' => 'triangle-alert', 'bad' => 'circle-alert'][$kind] ?? 'info')
<div {{ $attributes->class(['lt-status', 'lt-status--'.$kind => $kind !== 'neutral']) }}>
    <x-ds.icon :name="$icon" />
    <p class="lt-status__text">{{ $slot }}</p>
    @if (isset($action) && trim((string) $action) !== '')<div class="lt-status__action">{{ $action }}</div>@endif
</div>
