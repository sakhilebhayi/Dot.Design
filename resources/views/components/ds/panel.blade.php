@props(['title' => null, 'bordered' => false, 'sunk' => false])
<section {{ $attributes->class(['lt-panel', 'lt-panel--bordered' => $bordered, 'lt-panel--sunk' => $sunk]) }}>
    @if ($title || isset($actions))
    <div class="lt-panel__head">
        <h2 class="lt-panel__title">{{ $title }}</h2>
        @isset($actions)<div class="lt-pagehead__actions">{{ $actions }}</div>@endisset
    </div>
    @endif
    <div class="lt-panel__body">{{ $slot }}</div>
</section>
