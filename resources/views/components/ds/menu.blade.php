@props(['align' => 'right', 'side' => 'down', 'label' => 'Menu', 'triggerClass' => 'lt-icon-btn', 'icon' => null, 'triggerLabel' => null])
{{-- Popover menu. Trigger content goes in the `trigger` slot (or pass icon + label for an icon button). Items: <x-ds.menu-item>. --}}
<div data-lt-menu {{ $attributes->class(['lt-menu']) }}>
    <button type="button" data-lt-menu-trigger aria-haspopup="menu" aria-expanded="false" class="{{ $triggerClass }}" @if($triggerLabel) aria-label="{{ $triggerLabel }}" @endif>
        @isset($trigger){{ $trigger }}@else<x-ds.icon :name="$icon ?? 'more'" />@endisset
    </button>
    <div data-lt-menu-panel role="menu" aria-label="{{ $label }}" class="lt-menu__panel lt-menu__panel--{{ $align }} lt-menu__panel--{{ $side }}" hidden>{{ $slot }}</div>
</div>
