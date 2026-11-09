<div class="lt-formsection__intro">
    <h2 class="lt-formsection__title">{{ $title }}</h2>
    <p class="lt-formsection__desc">{{ $description }}</p>
    @if (trim((string) ($aside ?? '')) !== '')<div>{{ $aside }}</div>@endif
</div>
