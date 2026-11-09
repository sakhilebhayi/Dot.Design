<x-app-layout>
<div class="lt-page">
    <x-ds.page-header title="Token sets" sub="Colours, spacing and type shared by every Dot platform. Read by everyone, changed only by platform operators." />

    @if ($tokenSets->isEmpty())
        <x-ds.empty title="No token sets yet">When an operator publishes a set, its colours, spacing and type scale appear here.</x-ds.empty>
    @else
        <ul class="lt-cardlist" style="--min: 17.5rem;">
            @foreach ($tokenSets as $tokenSet)
                @php($swatchTokens = $tokenSet->tokens->where('category', 'color')->take(8))
                <li class="lt-listcard">
                    <div class="lt-listcard__head">
                        <h2 class="lt-h3"><a href="{{ route('design-system.token-sets.show', $tokenSet) }}" class="lt-listcard__link">{{ $tokenSet->name }}</a></h2>
                        <x-ds.tag kind="accent">v{{ $tokenSet->version }}</x-ds.tag>
                    </div>
                    <p class="lt-small">{{ $tokenSet->description ?: 'No description.' }}</p>
                    @if ($swatchTokens->isNotEmpty())
                        <div class="lt-strip" style="gap: var(--lt-space-1);">
                            @foreach ($swatchTokens as $token)<x-ds.swatch :color="$token->value" :title="$token->name" />@endforeach
                        </div>
                    @endif
                    <p class="lt-meta lt-num">{{ $tokenSet->tokens_count }} {{ Str::plural('token', $tokenSet->tokens_count) }}, {{ $tokenSet->consumption_records_count }} {{ Str::plural('consumer', $tokenSet->consumption_records_count) }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
</x-app-layout>
