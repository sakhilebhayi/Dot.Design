@push('head')
    @vite(['resources/css/pages/brand.css'])
@endpush
@php
    $fontName = fn ($key) => \Illuminate\Support\Str::headline((string) $key);
    $slotName = ['heading' => 'Heading font', 'body' => 'Body font', 'accent' => 'Accent font'];
@endphp
<x-app-layout>
<div class="lt-page lt-page--narrow">
    <x-ds.page-header title="Brand updates" :sub="count($notices) === 0 ? 'Your designs are up to date with their brand kits.' : count($notices).' '.\Illuminate\Support\Str::plural('design', count($notices)).' to review. The brand kit changed after '.(count($notices) === 1 ? 'it was' : 'they were').' made.'">
        <x-ds.button :href="route('design.brand.index')" variant="ghost" icon="arrow-left">All kits</x-ds.button>
    </x-ds.page-header>

    @if (count($notices) === 0)
        <x-ds.empty title="Nothing to review" icon="circle-check">
            When you change a brand kit, designs that took colours or fonts from it show up here. They never change on their own: you look at what would change and choose.
            <x-slot name="actions"><x-ds.button :href="route('design.brand.index')" variant="secondary">Open your brand kits</x-ds.button></x-slot>
        </x-ds.empty>
    @else
        <p class="lt-small" style="margin-bottom: var(--lt-space-5); max-width: 60ch;">Nothing in these designs has changed. Open one to see exactly what would change, then apply all of it, only the colours, only the fonts, or just the changes you pick.</p>
        <ul class="br-notices">
            @foreach ($notices as $n)
                @php $summary = (array) ($n['summary'] ?? []); @endphp
                <li class="br-notice">
                    <a class="br-notice__stage" href="{{ $n['review_url'] }}" tabindex="-1" aria-hidden="true">
                        <x-ds.frame :width="$n['width'] ?? 4" :height="$n['height'] ?? 3" :blank="! $n['thumbnail_url']">
                            @if ($n['thumbnail_url'])<img src="{{ $n['thumbnail_url'] }}" alt="" loading="lazy" width="{{ $n['width'] }}" height="{{ $n['height'] }}">@endif
                        </x-ds.frame>
                    </a>
                    <div class="br-notice__body">
                        <p class="br-notice__name">{{ $n['project_name'] }}</p>
                        <p class="lt-small">{{ $n['kit_name'] }} moved from version {{ $n['from_version'] }} to {{ $n['to_version'] }}.</p>
                        <div class="br-sum">
                            @foreach ((array) ($summary['colors'] ?? []) as $c)
                                <span class="br-sum__item" title="{{ $c['name'] ?? 'Colour' }}">
                                    @if (! empty($c['from']))<span class="br-chip__sw" style="--c: {{ $c['from'] }}"></span>@endif
                                    @if (! empty($c['from']) && ! empty($c['to']))<x-ds.icon name="arrow-right" size="sm" class="br-arrow" />@endif
                                    @if (! empty($c['to']))<span class="br-chip__sw" style="--c: {{ $c['to'] }}"></span>@endif
                                    <span>{{ $c['name'] ?? 'Colour' }}@if (empty($c['from'])) added @elseif (empty($c['to'])) removed @endif</span>
                                </span>
                            @endforeach
                            @foreach ((array) ($summary['fonts'] ?? []) as $f)
                                <span class="br-sum__item">{{ $slotName[$f['slot']] ?? 'Font' }}: {{ $fontName($f['from'] ?? '') }} <x-ds.icon name="arrow-right" size="sm" class="br-arrow" /> {{ $fontName($f['to'] ?? '') }}</span>
                            @endforeach
                            @foreach ((array) ($summary['other'] ?? []) as $o)
                                <span class="br-sum__item">{{ ucfirst($o) }} changed</span>
                            @endforeach
                        </div>
                    </div>
                    <div><x-ds.button :href="$n['review_url']" variant="secondary" size="sm">Review changes</x-ds.button></div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
</x-app-layout>
