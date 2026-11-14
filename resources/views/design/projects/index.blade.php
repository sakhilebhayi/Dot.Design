@php
    $items = $listing['items'];
    $createRoute = route('design.start');
    $query = array_filter(['q' => $listing['q'], 'type' => $listing['type'], 'status' => $listing['status'], 'sort' => $listing['sort'] !== 'recent' ? $listing['sort'] : null]);
    $link = fn (array $change) => route('design.projects.index', array_filter(array_merge($query, $change), fn ($v) => $v !== null && $v !== ''));
    $sortLabels = ['recent' => 'Recently edited', 'name' => 'Name', 'oldest' => 'Oldest first'];
    $brandUpdated = \App\Support\Ui\ProjectCard::brandUpdatedIds($items);
@endphp
<x-app-layout>
<div class="lt-page">
    <x-ds.page-header title="Designs" :sub="$listing['total'] === 0 ? 'Everything you make lives here.' : $listing['total'].' '.\Illuminate\Support\Str::plural('design', $listing['total'])">
        <x-ds.button :href="$createRoute" icon="plus">New design</x-ds.button>
    </x-ds.page-header>

    @if ($listing['total'] > 0)
    <div class="lt-toolbar">
        <form method="GET" action="{{ route('design.projects.index') }}" role="search" class="lt-toolbar__search">
            @foreach (['type', 'status'] as $keep)@if ($listing[$keep])<input type="hidden" name="{{ $keep }}" value="{{ $listing[$keep] }}">@endif @endforeach
            @if ($listing['sort'] !== 'recent')<input type="hidden" name="sort" value="{{ $listing['sort'] }}">@endif
            <x-dot.filter-search name="q" :value="$listing['q']" placeholder="Search designs" label="Search designs" />
        </form>

        <div class="lt-toolbar__filters" role="group" aria-label="Filter by type">
            <x-ds.chip :href="$link(['type' => null])" :current="$listing['type'] === null">All</x-ds.chip>
            @foreach ($listing['types'] as $type => $count)
                <x-ds.chip :href="$link(['type' => $type])" :current="$listing['type'] === $type">{{ \Illuminate\Support\Str::headline($type) }} <span class="lt-num" style="opacity: 0.7;">{{ $count }}</span></x-ds.chip>
            @endforeach
        </div>
        @if ($listing['statuses']->isNotEmpty())
        <div class="lt-toolbar__filters" role="group" aria-label="Filter by status">
            @foreach ($listing['statuses'] as $status => $count)
                <x-ds.chip :href="$link(['status' => $listing['status'] === $status ? null : $status])" :current="$listing['status'] === $status">{{ \Illuminate\Support\Str::headline($status) }}</x-ds.chip>
            @endforeach
        </div>
        @endif

        <div class="lt-toolbar__end" style="display: flex; gap: var(--lt-space-2); align-items: center;">
            <x-ds.menu align="right" label="Sort designs" trigger-class="lt-btn lt-btn--secondary lt-btn--sm" icon="list">
                <x-slot name="trigger">{{ $sortLabels[$listing['sort']] }} <x-ds.icon name="chevron-down" size="sm" /></x-slot>
                @foreach ($sortLabels as $key => $text)
                    <x-ds.menu-item :href="$link(['sort' => $key === 'recent' ? null : $key])" :current="$listing['sort'] === $key" :icon="$listing['sort'] === $key ? 'check' : null">{{ $text }}</x-ds.menu-item>
                @endforeach
            </x-ds.menu>
            <div class="lt-seg" role="group" aria-label="View">
                <button type="button" data-lt-view="grid" aria-pressed="true" aria-label="Grid view" data-tip="Grid"><x-ds.icon name="layout-grid" /></button>
                <button type="button" data-lt-view="list" aria-pressed="false" aria-label="List view" data-tip="List"><x-ds.icon name="list" /></button>
            </div>
        </div>
    </div>
    @endif

    @if ($listing['total'] === 0)
        <x-ds.empty title="No designs yet">
            Posters, social posts, flyers and cards each start from a size. Pick one and the canvas opens at its true proportions.
            <x-slot name="actions">
                <x-ds.button :href="$createRoute" icon="plus">Start a design</x-ds.button>
                <x-ds.button :href="route('design.templates.index')" variant="ghost">Start from a template</x-ds.button>
            </x-slot>
        </x-ds.empty>
        <div class="lt-start" role="group" aria-label="Sizes" style="margin-top: var(--lt-space-5);">
            @foreach (\App\Support\Ui\StartFormats::all() as $format)
                <x-ds.format-chip :href="$createRoute.'?format='.$format['key']" :name="$format['name']" :width="$format['width']" :height="$format['height']" :size="$format['width'].' × '.$format['height'].' '.$format['unit']" />
            @endforeach
        </div>
    @elseif ($items->isEmpty())
        <x-ds.empty title="No designs match">
            @if ($listing['q'] !== '')Nothing is named like "{{ $listing['q'] }}"@if ($listing['type'] || $listing['status']) with those filters @endif. @else No designs have those filters. @endif
            <x-slot name="actions"><x-ds.button :href="route('design.projects.index')" variant="secondary">Clear search and filters</x-ds.button></x-slot>
        </x-ds.empty>
    @else
        <p class="lt-meta" role="status" style="margin-bottom: var(--lt-space-4);">@if ($listing['filtered']) Showing {{ $listing['matched'] }} of {{ $listing['total'] }} @else {{ $listing['total'] }} {{ \Illuminate\Support\Str::plural('design', $listing['total']) }} @endif @if ($listing['paginator']->hasPages()) · page {{ $listing['paginator']->currentPage() }} of {{ $listing['paginator']->lastPage() }} @endif</p>
        <ul class="lt-contacts lt-views">
            @foreach ($items as $project)
                <li class="lt-contact">
                    <a href="{{ route('design.projects.show', $project) }}" class="lt-contact__stage" tabindex="-1" aria-hidden="true"><x-ds.frame :width="$project->width" :height="$project->height" :src="\App\Support\Ui\ProjectCard::thumbnailUrl($project)" class="lt-crop lt-crop--link" /></a>
                    <div class="lt-contact__meta">
                        <a href="{{ route('design.projects.show', $project) }}" class="lt-contact__name">{{ $project->name }}</a>
                        <span class="lt-contact__sub">{{ \App\Support\Ui\DesignListing::sizeLabel($project) }}</span>
                        <span class="lt-contact__sub">Edited {{ $project->updated_at?->diffForHumans() ?? 'never' }}</span>
                        @php($chip = \App\Support\Ui\ProjectCard::status($project))
                        <span class="lt-contact__chips">
                            @if ($chip)<x-ds.tag :kind="$chip['kind']">{{ $chip['label'] }}</x-ds.tag>@endif
                            @if (in_array($project->id, $brandUpdated, true))<a href="{{ route('design.brand.updates.show', $project) }}" class="lt-tag lt-tag--warn" title="The brand kit changed. Review what would change.">Brand updated</a>@endif
                        </span>
                    </div>
                    <div class="lt-contact__extra">
                        <x-ds.tag>{{ \Illuminate\Support\Str::headline($project->type) }}</x-ds.tag>
                        <span>{{ $project->canvases_count }} {{ \Illuminate\Support\Str::plural('page', $project->canvases_count) }}</span>
                    </div>
                    <div class="lt-contact__actions">
                        <x-ds.icon-button icon="trash" :label="'Delete '.$project->name" data-lt-dialog-open="delete-design" :data-lt-action="route('design.projects.destroy', $project)" :data-lt-name="$project->name" />
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($listing['paginator']->hasPages())
            <nav class="lt-toolbar" aria-label="Pages of designs" style="margin-top: var(--lt-space-5); justify-content: center; gap: var(--lt-space-3);">
                @if ($listing['paginator']->onFirstPage())<span class="lt-btn lt-btn--secondary lt-btn--sm" aria-disabled="true" style="opacity: .5;">Newer</span>@else<x-ds.button :href="$listing['paginator']->previousPageUrl()" variant="secondary" size="sm" rel="prev">Newer</x-ds.button>@endif
                @if ($listing['paginator']->hasMorePages())<x-ds.button :href="$listing['paginator']->nextPageUrl()" variant="secondary" size="sm" rel="next">Older</x-ds.button>@else<span class="lt-btn lt-btn--secondary lt-btn--sm" aria-disabled="true" style="opacity: .5;">Older</span>@endif
            </nav>
        @endif
    @endif

    <x-ds.dialog id="delete-design" title="Delete this design?">
        <p><strong data-lt-dialog-name></strong> and all of its pages will be deleted. This cannot be undone.</p>
        <x-slot name="footer">
            <x-ds.button variant="secondary" data-lt-dialog-close>Keep it</x-ds.button>
            <form method="POST" action="#" data-lt-dialog-form>@csrf @method('DELETE')<x-ds.button type="submit" variant="danger">Delete design</x-ds.button></form>
        </x-slot>
    </x-ds.dialog>
</div>
</x-app-layout>
