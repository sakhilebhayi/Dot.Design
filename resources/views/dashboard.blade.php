@php
    $user = auth()->user();
    $team = $user?->currentTeam;
    $formats = \App\Support\Ui\StartFormats::all();
    $createRoute = route('design.projects.create');
    $recent = $projects->sortByDesc(fn ($p) => $p->updated_at?->getTimestamp() ?? 0)->take(8)->values();
    $brand = \App\Support\Ui\BrandStatus::for($user);
    $brandRoute = collect(['design.brand.index', 'brand.index', 'design.brand-kits.index'])->first(fn ($name) => \Illuminate\Support\Facades\Route::has($name));
    $assetRoute = collect(['design.assets.index', 'assets.index'])->first(fn ($name) => \Illuminate\Support\Facades\Route::has($name));
    $brandUpdated = \App\Support\Ui\ProjectCard::brandUpdatedIds($recent);
@endphp
<x-app-layout>
<div class="lt-page">

    <x-ds.page-header title="Home" :sub="now()->format('l j F').($team ? ', '.$team->name : '')">
        <x-ds.button :href="$createRoute" icon="plus">New design</x-ds.button>
    </x-ds.page-header>

    <section aria-labelledby="start-title">
        <div class="lt-section__head">
            <h2 id="start-title" class="lt-section__title">Start something</h2>
            <p class="lt-small">Pick a size. The canvas opens at that size.</p>
        </div>
        <div class="lt-start" role="list">
            @foreach ($formats as $format)
                <x-ds.format-chip role="listitem" :href="$createRoute.'?format='.$format['key']" :name="$format['name']" :width="$format['width']" :height="$format['height']" :size="$format['width'].' × '.$format['height'].' '.$format['unit']" />
            @endforeach
        </div>
        <div class="lt-ruler lt-start__rule" aria-hidden="true"></div>
    </section>

    <div class="lt-home lt-home--split lt-section">
        <section aria-labelledby="recent-title">
            <div class="lt-section__head">
                <h2 id="recent-title" class="lt-section__title">Recent designs</h2>
                @if ($projects->isNotEmpty())<a href="{{ route('design.projects.index') }}" class="lt-small" style="text-decoration: underline; text-underline-offset: 0.2em;">View all designs</a>@endif
            </div>
            @if ($recent->isEmpty())
                <x-ds.empty title="Nothing on the table yet">
                    Choose a size above and your first design will appear here, at its true proportions.
                    <x-slot name="actions"><x-ds.button :href="$createRoute" icon="plus">Start a design</x-ds.button></x-slot>
                </x-ds.empty>
            @else
                <div class="lt-contacts">
                    @foreach ($recent as $project)
                        <a href="{{ route('design.projects.show', $project) }}" class="lt-contact">
                            <span class="lt-contact__stage"><x-ds.frame :width="$project->width" :height="$project->height" :src="\App\Support\Ui\ProjectCard::thumbnailUrl($project)" class="lt-crop lt-crop--link" /></span>
                            @php($chip = \App\Support\Ui\ProjectCard::status($project))
                            <span class="lt-contact__meta">
                                <span class="lt-contact__name">{{ $project->name }}</span>
                                <span class="lt-contact__sub">{{ \App\Support\Ui\DesignListing::sizeLabel($project) }}</span>
                                <span class="lt-contact__sub">Edited {{ $project->updated_at?->diffForHumans() ?? 'never' }}</span>
                                @if (($chip && $chip['label'] !== 'Draft') || in_array($project->id, $brandUpdated, true))
                                    <span class="lt-contact__chips">
                                        @if ($chip && $chip['label'] !== 'Draft')<x-ds.tag :kind="$chip['kind']">{{ $chip['label'] }}</x-ds.tag>@endif
                                        @if (in_array($project->id, $brandUpdated, true))<x-ds.tag kind="warn">Brand updated</x-ds.tag>@endif
                                    </span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <aside class="lt-side" aria-label="Brand, assets and activity">
            <section aria-labelledby="brand-title">
                <h2 id="brand-title" class="lt-h3" style="margin-bottom: var(--lt-space-3);">Brand</h2>
                @if ($brand)
                    <x-ds.status-line :kind="$brand['outdated'] > 0 ? 'warn' : 'ok'" icon="swatch">
                        {{ $brand['name'] }}@if($brand['version']), version {{ $brand['version'] }}@endif
                        @if ($brand['outdated'] > 0)<br><a href="{{ route('design.brand.updates') }}" style="text-decoration: underline; text-underline-offset: 0.2em;">{{ $brand['outdated'] }} {{ \Illuminate\Support\Str::plural('design', $brand['outdated']) }} to review</a>@endif
                        @if ($brand['colors'])
                            <span style="display: flex; gap: var(--lt-space-1); margin-top: var(--lt-space-2);">@foreach ($brand['colors'] as $hex)<x-ds.swatch :color="$hex" />@endforeach</span>
                        @endif
                        <x-slot name="action">@if ($brandRoute)<x-ds.button :href="route($brandRoute)" variant="secondary" size="sm">Open</x-ds.button>@endif</x-slot>
                    </x-ds.status-line>
                @else
                    <x-ds.status-line icon="swatch">
                        No brand kit yet.
                        <x-slot name="action">@if ($brandRoute)<x-ds.button :href="route($brandRoute)" variant="secondary" size="sm">Set up</x-ds.button>@endif</x-slot>
                    </x-ds.status-line>
                @endif
            </section>

            <section aria-labelledby="assets-title" style="margin-top: var(--lt-space-6);">
                <div class="lt-section__head" style="margin-bottom: var(--lt-space-3);">
                    <h2 id="assets-title" class="lt-h3">Recently added assets</h2>
                    @if ($assetRoute && $recentAssets->isNotEmpty())<a href="{{ route($assetRoute) }}" class="lt-small" style="text-decoration: underline; text-underline-offset: 0.2em;">Open assets</a>@endif
                </div>
                @if ($recentAssets->isEmpty())
                    <p class="lt-small">No assets yet. Pictures you add inside a design are kept here.</p>
                @else
                    <ul class="lt-assetstrip">
                        @foreach ($recentAssets->take(6) as $asset)
                            <li class="lt-asset" title="{{ $asset->name }}{{ $asset->isPlaceholder() ? ' (placeholder)' : '' }}">
                                @if ($asset->type === 'image' && $asset->displayUrl())
                                    <img src="{{ $asset->displayUrl() }}" alt="{{ $asset->name }}" loading="lazy" width="88" height="88">
                                @else
                                    <x-ds.icon name="page" size="lg" class="lt-faint" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($aiGenerations > 0)
            <section aria-labelledby="ai-title" style="margin-top: var(--lt-space-6);">
                <h2 id="ai-title" class="lt-h3" style="margin-bottom: var(--lt-space-3);">Image requests</h2>
                <ul style="display: grid; gap: var(--lt-space-2);">
                    @foreach ($generationsByProvider as $provider => $count)
                        <li><x-ds.stat-line :value="$count" :label="$provider === 'mock' ? \App\Services\Ai\AiImageResult::PLACEHOLDER_LABEL : \Illuminate\Support\Str::headline($provider)" /></li>
                    @endforeach
                </ul>
            </section>
            @endif
        </aside>
    </div>
</div>
</x-app-layout>
