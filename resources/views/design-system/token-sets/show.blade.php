<x-app-layout>
<div class="lt-page">
    <x-ds.page-header :title="$tokenSet->name" :sub="$tokenSet->description ?: 'No description.'">
        <x-ds.tag kind="accent">v{{ $tokenSet->version }}</x-ds.tag>
        <x-ds.button :href="route('design-system.token-sets.index')" variant="ghost" icon="arrow-left" size="sm">All token sets</x-ds.button>
    </x-ds.page-header>

    <section aria-labelledby="tokens-title">
        <h2 id="tokens-title" class="lt-section__title" style="margin-bottom: var(--lt-space-4);">Tokens</h2>
        @if ($tokenSet->tokens->isEmpty())
            <x-ds.empty title="No tokens in this set yet">An operator adds tokens one at a time. Each shows a live preview of its value.</x-ds.empty>
        @else
            <ul class="lt-cardlist" style="--min: 13.5rem;">
                @foreach ($tokenSet->tokens as $token)
                    <li class="lt-listcard">
                        <div class="lt-tokenview lt-stage">
                            @if ($token->category === 'color')
                                <span class="lt-tokenview__color" style="background: {{ preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $token->value) ? $token->value : 'transparent' }};"></span>
                            @elseif ($token->category === 'spacing')
                                <span class="lt-tokenview__space" style="width: {{ min((float) $token->value * 16, 100) }}%;"></span>
                            @elseif ($token->category === 'type')
                                <span class="lt-tokenview__type" style="font-size: {{ min(max((float) $token->value * 16, 12), 40) }}px;">Aa</span>
                            @else
                                <x-ds.icon name="zap" class="lt-faint" size="lg" />
                            @endif
                        </div>
                        <div class="lt-listcard__head">
                            <h3 class="lt-h3" style="font-size: var(--lt-text-md);">{{ $token->name }}</h3>
                            <x-ds.tag>v{{ $token->version }}</x-ds.tag>
                        </div>
                        <p class="lt-meta">{{ $token->category }}, <span class="lt-mono">{{ $token->value }}</span></p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="consumers-title" class="lt-section">
        <h2 id="consumers-title" class="lt-section__title" style="margin-bottom: var(--lt-space-4);">Consuming platforms</h2>
        @if ($tokenSet->consumptionRecords->isEmpty())
            <x-ds.empty title="No consumers recorded yet">Platforms that pin this set are listed here with the version they use.</x-ds.empty>
        @else
            <div class="table-scroll lt-table-scroll" tabindex="0" role="region" aria-label="Consuming platforms">
                <table class="lt-table">
                    <thead><tr><th scope="col">Platform</th><th scope="col">Pinned version</th><th scope="col">Last synced</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @foreach ($tokenSet->consumptionRecords as $record)
                            <tr>
                                <td>{{ $record->platform_id }}</td>
                                <td class="lt-num">v{{ $record->pinned_version }}</td>
                                <td class="lt-muted">{{ $record->last_synced_at?->diffForHumans() ?? 'never synced' }}</td>
                                <td>@if (in_array($record->platform_id, $driftedPlatforms))<x-ds.tag kind="bad">Drifted</x-ds.tag>@else<x-ds.tag kind="ok">Up to date</x-ds.tag>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
</x-app-layout>
