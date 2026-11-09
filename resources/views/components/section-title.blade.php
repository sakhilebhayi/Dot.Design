<div class="lt-formsection__intro">
    <h3 class="lt-formsection__title">{{ $title }}</h3>
    <p class="lt-formsection__desc">{{ $description }}</p>
    @if (trim((string) ($aside ?? '')) !== '')<div>{{ $aside }}</div>@endif
</div>
