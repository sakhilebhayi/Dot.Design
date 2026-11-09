@props(['title', 'icon' => null, 'center' => false, 'mark' => 'registration'])
{{-- An empty state says what belongs here and offers the one next step. The mark is drawn, never a stock illustration. --}}
<div {{ $attributes->class(['lt-empty', 'lt-empty--center' => $center]) }}>
    <span class="lt-empty__mark lt-crop lt-crop--quiet" style="--crop-len: 7px; --crop-gap: 5px; display: inline-grid; place-items: center; width: 2.75rem; height: 2.75rem; color: var(--lt-ink-3);"><x-ds.icon :name="$icon ?? $mark" size="lg" /></span>
    <h2 class="lt-empty__title">{{ $title }}</h2>
    @if (trim((string) $slot) !== '')<p class="lt-empty__text">{{ $slot }}</p>@endif
    @if (isset($actions) && trim((string) $actions) !== '')<div class="lt-empty__actions">{{ $actions }}</div>@endif
</div>
