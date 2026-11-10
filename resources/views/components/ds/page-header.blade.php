@props(['title', 'sub' => null])
@php(request()->attributes->set('page_title', trim(strip_tags((string) $title))))
<header {{ $attributes->class(['lt-pagehead']) }}>
    <div class="lt-pagehead__text">
        <h1 class="lt-h1">{{ $title }}</h1>
        @if ($sub)<p class="lt-pagehead__sub">{{ $sub }}</p>@endif
    </div>
    @if (trim((string) $slot) !== '')<div class="lt-pagehead__actions">{{ $slot }}</div>@endif
</header>
