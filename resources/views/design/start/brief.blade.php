<x-app-layout>
@push('head')
    @vite(['resources/js/studio/pages/brief.js'])
@endpush

<div class="lt-page cf-brief-page">
    <x-ds.page-header :title="$goal['label']" sub="A few short questions, then designs to start from. You can skip most of them.">
        <x-ds.button :href="route('design.start')" variant="ghost" icon="arrow-left">Choose something else</x-ds.button>
    </x-ds.page-header>

    <div class="cf-brief" id="brief-root" data-cf-brief>
        <noscript>
            <x-ds.status-line kind="warn">
                The step-by-step helper needs JavaScript, which is switched off in this browser. You can still start from a ready-made design or a blank canvas.
                <x-slot name="action">
                    <x-ds.button :href="route('design.templates.index')" variant="secondary" size="sm">Browse designs</x-ds.button>
                </x-slot>
            </x-ds.status-line>
        </noscript>
        <div class="cf-brief__loading lt-small" data-cf-loading>Getting your questions ready...</div>
    </div>

    <script type="application/json" id="brief-boot">{!! \Illuminate\Support\Js::encode($boot) !!}</script>
</div>
</x-app-layout>
