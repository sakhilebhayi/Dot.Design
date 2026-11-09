@push('head')
    @vite(['resources/css/pages/brand.css', 'resources/js/studio/pages/brand-editor.js'])
@endpush
@php
    $isNew = $kit === null;
    $canEdit = $isNew || ($kit['can']['update'] ?? false);
    $title = $isNew ? 'New brand kit' : $kit['name'];
@endphp
@php
    $boot = [
        'kit' => $kit,
        'options' => $options,
        'fonts' => $fonts,
        'limits' => $limits,
        'canEdit' => $canEdit,
        'urls' => [
            'kits' => url('/design/brand/kits'),
            'kit' => $kit ? url('/design/brand/kits/'.$kit['id']) : null,
            'edit' => $kit ? route('design.brand.edit', $kit['id']) : null,
            'index' => route('design.brand.index'),
            'palette' => route('design.brand.palette'),
            'assetUpload' => route('design.assets.store'),
            'assetLibrary' => route('design.assets.library'),
            'templates' => url('/design/templates'),
            'assets' => route('design.assets.index'),
            'updates' => route('design.brand.updates'),
        ],
    ];
@endphp
<x-app-layout>
<div class="lt-page lt-page--wide" id="brand-editor" data-can-edit="{{ $canEdit ? '1' : '0' }}">
    <x-ds.page-header :title="$title" :sub="$isNew ? 'Set up your logo, colours, fonts and voice. Nothing is saved until you press Save.' : 'Version '.$kit['version'].($kit['is_default'] ? ', your default kit' : '').($kit['projects_using'] ? ', used by '.$kit['projects_using'].' '.\Illuminate\Support\Str::plural('design', $kit['projects_using']) : '')">
        <x-ds.button :href="route('design.brand.index')" variant="ghost" icon="arrow-left" data-leave>All kits</x-ds.button>
    </x-ds.page-header>

    @if (! $canEdit)
        <x-ds.status-line icon="lock" class="br-errors">Your role in this workspace lets you look at this kit but not change it.</x-ds.status-line>
    @endif

    <div class="br-savebar" data-savebar data-state="clean" role="region" aria-label="Save">
        <p class="br-savebar__status" role="status" aria-live="polite"><x-ds.icon name="circle-check" size="sm" data-i="ok" /><x-ds.icon name="triangle-alert" size="sm" data-i="alert" /><span data-save-text>{{ $isNew ? 'Not saved yet' : 'All changes saved' }}</span></p>
        <div class="br-savebar__actions">
            <x-ds.button variant="ghost" data-discard hidden>Discard changes</x-ds.button>
            @if ($canEdit)<x-ds.button data-save disabled>{{ $isNew ? 'Create kit' : 'Save changes' }}</x-ds.button>@endif
        </div>
    </div>
    <div class="br-errors" data-error-summary hidden></div>

    <div class="br-ed">
        <div class="br-ed__main">
            <section class="lt-formsection" aria-labelledby="s-name" style="border-top: 0; padding-top: 0;">
                <div class="lt-formsection__intro"><h2 id="s-name" class="lt-formsection__title">Name</h2><p class="lt-formsection__desc">Usually your business name. Only you and your workspace see it.</p></div>
                <div class="lt-formsection__body" data-section="name"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-logo">
                <div class="lt-formsection__intro"><h2 id="s-logo" class="lt-formsection__title">Logo</h2><p class="lt-formsection__desc">Upload it once. Templates use it wherever a logo goes.</p></div>
                <div class="lt-formsection__body" data-section="logo"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-colors">
                <div class="lt-formsection__intro"><h2 id="s-colors" class="lt-formsection__title">Colours</h2><p class="lt-formsection__desc">A main colour, a second, a highlight and a neutral for text and backgrounds. Add extras if you need them.</p></div>
                <div class="lt-formsection__body" data-section="colors"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-fonts">
                <div class="lt-formsection__intro"><h2 id="s-fonts" class="lt-formsection__title">Fonts</h2><p class="lt-formsection__desc">One font for headings, one for body text and one for accents.</p></div>
                <div class="lt-formsection__body" data-section="fonts"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-styles">
                <div class="lt-formsection__intro"><h2 id="s-styles" class="lt-formsection__title">Text styles</h2><p class="lt-formsection__desc">Sizes and weights you reuse, up to 12.</p></div>
                <div class="lt-formsection__body" data-section="styles"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-voice">
                <div class="lt-formsection__intro"><h2 id="s-voice" class="lt-formsection__title">Voice and guidelines</h2><p class="lt-formsection__desc">How your brand sounds, in a few words.</p></div>
                <div class="lt-formsection__body" data-section="voice"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-contact">
                <div class="lt-formsection__intro"><h2 id="s-contact" class="lt-formsection__title">Tagline, website and contacts</h2><p class="lt-formsection__desc">Templates fill these in for you, for example a phone number on a flyer.</p></div>
                <div class="lt-formsection__body" data-section="contact"></div>
            </section>
            <section class="lt-formsection" aria-labelledby="s-imagery">
                <div class="lt-formsection__intro"><h2 id="s-imagery" class="lt-formsection__title">Brand imagery</h2><p class="lt-formsection__desc">Patterns, backgrounds and pictures that belong to your brand.</p></div>
                <div class="lt-formsection__body" data-section="imagery"></div>
            </section>
        </div>

        <aside class="br-preview" aria-labelledby="pv-title" data-section="preview"></aside>
    </div>

    <script type="application/json" id="brand-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</div>
</x-app-layout>
