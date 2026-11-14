@php
    $user = auth()->user();
    $team = $user?->currentTeam;
    $formats = \App\Support\Ui\StartFormats::all();
    $createRoute = route('design.start');
    $recent = $projects->sortByDesc(fn ($p) => $p->updated_at?->getTimestamp() ?? 0)->take(9)->values();
    // The newest design is the proof on the table; the strip below holds the ones before it.
    $latest = $recent->first();
    $earlier = $recent->slice(1)->values();
    $brand = \App\Support\Ui\BrandStatus::for($user);
    $brandRoute = collect(['design.brand.index', 'brand.index', 'design.brand-kits.index'])->first(fn ($name) => \Illuminate\Support\Facades\Route::has($name));
    $assetRoute = collect(['design.assets.index', 'assets.index'])->first(fn ($name) => \Illuminate\Support\Facades\Route::has($name));
    $brandUpdated = \App\Support\Ui\ProjectCard::brandUpdatedIds($recent);
    $milestone = app(\App\Support\Ui\Milestones::class)->pending($user);
    $firstName = trim((string) \Illuminate\Support\Str::of((string) $user?->name)->trim()->before(' '));
    $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');
    request()->attributes->set('page_title', 'Home'); // the browser tab keeps the name the navigation uses
@endphp
<x-app-layout>
<div class="lt-page">

    {{-- The arrival: the real logo, a welcome from the signed-in person and their workspace, what the studio is for, and the one
         primary action of this page. Everything else on the page is secondary and looks it. --}}
    <header class="lt-arrive">
        <div class="lt-arrive__top">
            <x-ds.logo class="lt-arrive__logo" alt="Dot.Design" loading="eager" />
            @if ($infodot !== '')
                <nav class="lt-arrive__back" aria-label="Dot ecosystem">
                    <a href="{{ $infodot }}/home"><x-ds.icon name="arrow-left" size="sm" />InfoDot</a>
                    <a href="{{ $infodot }}/platforms"><x-ds.icon name="layout-grid" size="sm" />Platforms</a>
                </nav>
            @endif
        </div>

        <div class="lt-arrive__table">
            <div class="lt-arrive__text">
                <h1 class="lt-display lt-arrive__title">Make it look like your business.</h1>
                <p class="lt-arrive__welcome">Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.@if ($team) This is the studio for {{ $team->name }}.@endif</p>
                <p class="lt-arrive__lead">Posters, social posts, flyers and cards, drawn at their true size and kept in your own colours and type.</p>
                <div class="lt-arrive__action">
                    <x-ds.button :href="$createRoute" icon="plus" size="lg" data-primary-action>Start a design</x-ds.button>
                </div>
            </div>

            <div class="lt-arrive__proof">
                @if ($latest)
                    <h2 class="lt-sr">Your latest design</h2>
                    <x-ds.design-tile :project="$latest" :brand-updated="in_array($latest->id, $brandUpdated, true)" class="lt-contact--proof" />
                @else
                    <div class="lt-contact lt-contact--proof" data-empty-state>
                        <span class="lt-contact__stage"><x-ds.frame :width="4" :height="5" class="lt-crop"><span class="lt-arrive__mark" aria-hidden="true"><x-ds.icon name="registration" size="lg" /></span></x-ds.frame></span>
                        <div class="lt-contact__meta">
                            <h2 class="lt-contact__name">Nothing on the table yet</h2>
                            <p class="lt-arrive__empty">Your first design will sit here at its true proportions. <a href="{{ route('design.templates.index') }}">Start from a template</a> if you would rather not begin with a blank page.</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <div class="lt-ruler" aria-hidden="true"></div>
    </header>

    <x-ds.milestone :milestone="$milestone" />

    <section aria-labelledby="start-title">
        <div class="lt-section__head">
            <h2 id="start-title" class="lt-section__title">Start something</h2>
            <p class="lt-small">Pick a size, then open a blank canvas or a ready-made design.</p>
        </div>
        <div class="lt-start" role="group" aria-label="Sizes">
            @foreach ($formats as $format)
                <x-ds.format-chip :href="$createRoute.'?format='.$format['key']" :name="$format['name']" :width="$format['width']" :height="$format['height']" :size="$format['width'].' × '.$format['height'].' '.$format['unit']" />
            @endforeach
        </div>
        <div class="lt-ruler lt-start__rule" aria-hidden="true"></div>
    </section>

    <div @class(['lt-home', 'lt-home--split' => $latest, 'lt-section'])>
        @if ($latest)
        <section aria-labelledby="recent-title">
            <div class="lt-section__head">
                <h2 id="recent-title" class="lt-section__title">Recent designs</h2>
                <a href="{{ route('design.projects.index') }}" class="lt-small lt-arrive__more">View all designs</a>
            </div>
            @if ($earlier->isEmpty())
                <p class="lt-small">Your latest design is on the table above. The ones before it will line up here.</p>
            @else
                <div class="lt-contacts">
                    @foreach ($earlier as $project)
                        <x-ds.design-tile :project="$project" :brand-updated="in_array($project->id, $brandUpdated, true)" />
                    @endforeach
                </div>
            @endif
        </section>
        @endif

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

        </aside>
    </div>
</div>
</x-app-layout>
