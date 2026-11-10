<x-app-layout>
@push('head')
    @vite(['resources/js/studio/pages/templates.js'])
@endpush

<div class="lt-page lt-page--wide cf-templates" data-cf-templates>
    <x-ds.page-header title="Templates" sub="Start from a finished design, then change every word, picture and colour.">
        <x-ds.button :href="route('design.start')" variant="secondary" icon="plus">Start from scratch</x-ds.button>
    </x-ds.page-header>

    <div id="templates-root" class="cf-tpl" data-cf-root>
        <noscript>
            <x-ds.status-line kind="warn">
                Template previews are drawn in the browser, so they need JavaScript. It is switched off here.
                <x-slot name="action"><x-ds.button :href="route('design.start')" variant="secondary" size="sm">Start a design</x-ds.button></x-slot>
            </x-ds.status-line>
        </noscript>
        <div class="cf-tpl__loading lt-small" data-cf-loading>Getting the templates ready...</div>
    </div>

    <x-ds.dialog id="template-detail" title="Template" class="cf-detail">
        <div data-cf-detail></div>
    </x-ds.dialog>

    <script type="application/json" id="templates-boot">{!! \Illuminate\Support\Js::encode($boot) !!}</script>
</div>
</x-app-layout>
