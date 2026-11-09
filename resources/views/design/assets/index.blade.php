@push('head')
    @vite(['resources/css/pages/assets.css', 'resources/js/studio/pages/assets.js'])
@endpush
@php
    $quotaText = \App\Services\Assets\AssetUploader::formatBytes($usage['used']).' of '.\App\Services\Assets\AssetUploader::formatBytes($usage['quota']).' used';
    $boot = [
        'usage' => $usage,
        'limits' => ['max_kb' => (int) config('design.assets.max_kilobytes'), 'max_dimension' => (int) config('design.assets.max_dimension')],
        'userId' => auth()->id(),
        'urls' => [
            'library' => route('design.assets.library'),
            'meta' => route('design.assets.meta'),
            'upload' => route('design.assets.store'),
            'asset' => url('/design/assets'),
            'folders' => url('/design/asset-folders'),
            'tags' => url('/design/asset-tags'),
            'bulk' => route('design.assets.bulk'),
            'projects' => route('design.projects.index'),
        ],
    ];
@endphp
<x-app-layout>
<div class="lt-page lt-page--wide" id="assets-page">
    <x-ds.page-header title="Assets" :sub="'Pictures you and your workspace have added. '.$quotaText.'.'">
        <x-ds.button data-act="upload" icon="upload">Upload pictures</x-ds.button>
    </x-ds.page-header>

    <div class="as" data-as>
        <aside class="as-side" aria-label="Folders, tags and storage">
            <section class="as-quota" aria-labelledby="as-quota-t">
                <h2 id="as-quota-t" class="as-side__title">Storage</h2>
                <x-ds.progress :value="$usage['quota'] > 0 ? min(100, $usage['used'] / $usage['quota'] * 100) : 0" label="Storage used" data-quota-bar />
                <p class="lt-small" data-quota-text>{{ $quotaText }}</p>
            </section>
            <nav class="as-side__block" aria-labelledby="as-folders-t">
                <div class="as-side__head"><h2 id="as-folders-t" class="as-side__title">Folders</h2><button type="button" class="lt-icon-btn" data-act="new-folder" aria-label="New folder" data-tip="New folder"><x-ds.icon name="plus" /></button></div>
                <ul class="as-list" data-folders><li class="lt-skeleton" style="height: 2rem;"></li><li class="lt-skeleton" style="height: 2rem;"></li></ul>
            </nav>
            <section class="as-side__block" aria-labelledby="as-tags-t">
                <h2 id="as-tags-t" class="as-side__title">Tags</h2>
                <div class="as-tags" data-tags></div>
            </section>
        </aside>

        <div class="as-main">
            <div class="lt-toolbar as-toolbar" role="search">
                <div class="lt-toolbar__search"><div class="lt-input-wrap"><x-ds.icon name="search" /><input type="search" class="lt-input" data-f="q" placeholder="Search by name" aria-label="Search pictures by name" maxlength="100" autocomplete="off"></div></div>
                <div class="lt-toolbar__filters">
                    <label class="as-filter"><span class="lt-sr">Type</span><select class="lt-select" data-f="type" aria-label="Type"><option value="">Any type</option><option value="png">PNG</option><option value="jpeg">JPEG</option><option value="webp">WebP</option></select></label>
                    <label class="as-filter"><span class="lt-sr">Where it is used</span><select class="lt-select" data-f="used" aria-label="Used or unused"><option value="">Used or not</option><option value="used">Used in a design</option><option value="unused">Not used yet</option></select></label>
                    <label class="as-filter"><span class="lt-sr">Sort</span><select class="lt-select" data-f="sort" aria-label="Sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name">Name</option><option value="size">Largest first</option></select></label>
                </div>
                <div class="lt-toolbar__end">
                    <div class="lt-seg" role="group" aria-label="View">
                        <button type="button" data-as-view="grid" aria-pressed="true" aria-label="Grid view" data-tip="Grid"><x-ds.icon name="layout-grid" /></button>
                        <button type="button" data-as-view="list" aria-pressed="false" aria-label="List view" data-tip="List"><x-ds.icon name="list" /></button>
                    </div>
                </div>
            </div>
            <div class="as-active" data-active-filters hidden></div>
            <div class="as-bulk" data-bulk hidden role="region" aria-label="Selected pictures"></div>

            <div class="as-queue" data-queue hidden aria-label="Uploads"></div>

            <div class="as-drop" data-drop>
                <div data-results aria-live="polite"><div class="as-grid" data-skeleton>@for ($i = 0; $i < 8; $i++)<div class="lt-skeleton as-skel"></div>@endfor</div></div>
                <noscript><p class="lt-small">The assets library needs JavaScript to list, filter and upload pictures. Turn it on in your browser and reload.</p></noscript>
                <p class="as-dropnote" data-dropnote hidden><x-ds.icon name="upload" /> Drop pictures to upload them</p>
            </div>
            <div class="as-more" data-more hidden><button type="button" class="lt-btn lt-btn--secondary" data-act="more">Show more</button></div>

            <p class="lt-small as-licence"><x-ds.icon name="info" size="sm" /> Only upload pictures you have the right to use. Dot.Design has no stock library, so everything here is something you or your workspace added. Files are private: they open only for people who can see the design or workspace they belong to.</p>
        </div>
    </div>

    <input type="file" data-file-input accept="image/png,image/jpeg,image/webp" multiple hidden aria-label="Choose pictures to upload">

    <dialog id="as-detail" class="lt-dialog as-drawer" aria-labelledby="as-detail-title">
        <div class="as-drawer__wrap"><div class="as-drawer__box" data-detail></div></div>
    </dialog>

    <script type="application/json" id="assets-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</div>
</x-app-layout>
