@push('head')
    @vite(['resources/css/pages/brand.css', 'resources/js/studio/pages/brand.js'])
@endpush
@php
    $boot = ['fonts' => $fonts, 'urls' => ['kits' => url('/design/brand/kits')]];
@endphp
<x-app-layout>
<div class="lt-page">
    <x-ds.page-header title="Brand" :sub="count($kits) === 0 ? 'Logo, colours, fonts and voice in one place.' : count($kits).' '.\Illuminate\Support\Str::plural('brand kit', count($kits))">
        @if ($pendingTotal > 0)
            <x-ds.button :href="route('design.brand.updates')" variant="secondary" icon="refresh">{{ $pendingTotal }} {{ \Illuminate\Support\Str::plural('design', $pendingTotal) }} to review</x-ds.button>
        @endif
        @if ($canCreate)<x-ds.button :href="route('design.brand.create')" icon="plus">New brand kit</x-ds.button>@endif
    </x-ds.page-header>

    @if (count($kits) === 0)
        <x-ds.empty title="Give your business its own look" icon="swatch">
            A brand kit keeps your logo, colours, fonts and tone of voice together. Start a design from a template and it can pick them up, so every poster, post and card looks like you.
            <x-slot name="actions">
                @if ($canCreate)<x-ds.button :href="route('design.brand.create')" icon="plus">Create a brand kit</x-ds.button>@else<span class="lt-small">Your role in this workspace cannot create brand kits.</span>@endif
            </x-slot>
        </x-ds.empty>
    @else
        <ul class="br-kits" data-brand-list>
            @foreach ($kits as $kit)
                @php
                    $logo = collect($kit['assets'])->firstWhere('role', 'logo') ?? collect($kit['assets'])->firstWhere('role', 'logo-light') ?? collect($kit['assets'])->firstWhere('role', 'mark');
                    $kf = $kit['fonts'];
                @endphp
                <li class="br-kit" data-kit-id="{{ $kit['id'] }}" data-kit-name="{{ $kit['name'] }}">
                    <a class="br-kit__sheet" href="{{ route('design.brand.edit', $kit['id']) }}" aria-label="Open {{ $kit['name'] }}">
                        <span class="br-kit__stage">
                            <span class="br-kit__paper lt-frame lt-crop lt-crop--link" style="--w: 4; --h: 3">
                                @if ($logo && ($logo['asset']['url'] ?? null))
                                    <img class="br-kit__logo" src="{{ $logo['asset']['url'] }}" alt="{{ $kit['name'] }} logo" loading="lazy" width="{{ $logo['asset']['width'] ?? 160 }}" height="{{ $logo['asset']['height'] ?? 90 }}">
                                @else
                                    <span class="br-kit__wordmark" data-font="{{ $kf['heading'] ?? 'inter' }}">{{ $kit['name'] }}</span>
                                @endif
                            </span>
                        </span>
                        <span class="br-kit__chips" role="img" aria-label="Colours: {{ collect($kit['colors'])->map(fn ($c) => $c['name'].' '.$c['hex'])->implode(', ') ?: 'none yet' }}">
                            @forelse ($kit['colors'] as $color)
                                <span class="br-kit__chip" style="--c: {{ $color['hex'] }}" title="{{ $color['name'] }} {{ $color['hex'] }}"></span>
                            @empty
                                <span class="br-kit__chip br-kit__chip--empty"></span>
                            @endforelse
                        </span>
                        <span class="br-kit__type">
                            <span class="br-kit__aa" data-font="{{ $kf['heading'] ?? 'inter' }}" aria-hidden="true">Aa</span>
                            <span class="br-kit__fonts" data-font-names="{{ ($kf['heading'] ?? 'inter').'|'.($kf['body'] ?? 'inter') }}">{{ \App\Support\Ui\FontManifest::label($kf['heading'] ?? 'inter') }} and {{ \App\Support\Ui\FontManifest::label($kf['body'] ?? 'inter') }}</span>
                        </span>
                    </a>
                    <div class="br-kit__meta">
                        <div class="br-kit__title">
                            <h2 class="br-kit__name">{{ $kit['name'] }}</h2>
                            @if ($kit['is_default'])<x-ds.tag kind="accent">Default</x-ds.tag>@endif
                        </div>
                        <p class="lt-small">
                            Version {{ $kit['version'] }}.
                            @if ($kit['projects_using'] > 0) Used by {{ $kit['projects_using'] }} {{ \Illuminate\Support\Str::plural('design', $kit['projects_using']) }}. @else Not used by any design yet. @endif
                        </p>
                        @if ($kit['outdated_designs'] > 0)
                            <a class="br-kit__alert" href="{{ route('design.brand.updates') }}"><x-ds.icon name="triangle-alert" size="sm" /> {{ $kit['outdated_designs'] }} {{ \Illuminate\Support\Str::plural('design', $kit['outdated_designs']) }} {{ $kit['outdated_designs'] === 1 ? 'is' : 'are' }} behind this kit</a>
                        @endif
                    </div>
                    <div class="br-kit__actions">
                        <x-ds.button :href="route('design.brand.edit', $kit['id'])" variant="secondary" size="sm">{{ ($kit['can']['update'] ?? false) ? 'Edit' : 'View' }}</x-ds.button>
                        @if (($kit['can']['update'] ?? false) || ($kit['can']['delete'] ?? false))
                        <x-ds.menu align="right" label="More for {{ $kit['name'] }}" trigger-label="More actions for {{ $kit['name'] }}" trigger-class="lt-icon-btn">
                            @if (! $kit['is_default'] && ($kit['can']['update'] ?? false))
                                <x-ds.menu-item type="button" icon="check" data-kit-action="default">Make default</x-ds.menu-item>
                            @endif
                            @if ($kit['can']['delete'] ?? false)
                                <x-ds.menu-item type="button" icon="trash" data-kit-action="delete">Delete kit</x-ds.menu-item>
                            @endif
                        </x-ds.menu>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="lt-small br-note">The default kit is the one new designs offer to take colours and fonts from. Changing a kit never changes a design by itself: designs that used it show a "Brand updated" notice and you decide what to apply.</p>
    @endif

    <script type="application/json" id="brand-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
</div>
</x-app-layout>
