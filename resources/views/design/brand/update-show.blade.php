@push('head')
    @vite(['resources/css/pages/brand.css', 'resources/js/studio/pages/brand-updates.js'])
@endpush
@php
    $boot = [
        'project' => ['id' => $project->id, 'name' => $project->name, 'width' => $project->width, 'height' => $project->height],
        'canEdit' => $canEdit,
        'fonts' => $fonts,
        'urls' => [
            'brand' => route('design.projects.brand.show', $project),
            'apply' => route('design.projects.brand.apply', $project),
            'dismiss' => route('design.projects.brand.dismiss', $project),
            'document' => route('design.projects.document', $project),
            'design' => route('design.projects.show', $project),
            'updates' => route('design.brand.updates'),
            'brand_index' => route('design.brand.index'),
        ],
    ];
@endphp
<x-app-layout>
<div class="lt-page lt-page--wide" id="brand-review" data-project-id="{{ $project->id }}">
    <x-ds.page-header title="Brand update" :sub="$project->name">
        <x-ds.button :href="route('design.brand.updates')" variant="ghost" icon="arrow-left">All updates</x-ds.button>
        <x-ds.button :href="route('design.projects.show', $project)" variant="secondary" icon="external">Open the design</x-ds.button>
    </x-ds.page-header>

    <div data-review aria-live="polite">
        <div class="br-rev" data-loading>
            <div class="lt-skeleton" style="height: 3.5rem;"></div>
            <div class="lt-skeleton" style="height: 14rem;"></div>
            <p class="lt-small" role="status">Loading what would change</p>
        </div>
    </div>

    <script type="application/json" id="brand-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</div>
</x-app-layout>
