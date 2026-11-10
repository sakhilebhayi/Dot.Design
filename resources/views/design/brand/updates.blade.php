@push('head')
    @vite(['resources/css/pages/brand.css'])
@endpush
@php
    $fontName = fn ($key) => \App\Support\Ui\FontManifest::label((string) $key);
    $propName = ['color' => 'Colour', 'fill' => 'Fill colour', 'stroke_color' => 'Outline colour', 'shadow_color' => 'Shadow colour', 'font' => 'Font'];
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
                        @php $count = (int) ($n['counts']['changes'] ?? 0); $places = (int) ($n['counts']['elements'] ?? 0); @endphp
                        <p class="br-notice__count">
                            @if ($count > 0)
                                {{ $count }} {{ \Illuminate\Support\Str::plural('change', $count) }} in this design, on {{ $places }} {{ \Illuminate\Support\Str::plural('element', $places) }}.
                            @else
                                Nothing in this design is linked to what changed in the kit.
                            @endif
                        </p>
                        @if ($count > 0)
                            @php $groups = collect($n['changes'])->groupBy(fn ($c) => $c['prop'].'|'.$c['current'].'|'.$c['proposed']); @endphp
                            <ul class="br-sum" aria-label="Every change in {{ $n['project_name'] }}">
                                @foreach ($groups as $group)
                                    @php $c = $group->first(); $many = $group->count(); @endphp
                                    <li class="br-sum__item">
                                        <span class="br-sum__what">{{ $propName[$c['prop']] ?? $c['prop'] }}</span>
                                        @if ($c['kind'] === 'font')
                                            <span>{{ $fontName($c['current']) }}</span><x-ds.icon name="arrow-right" size="sm" class="br-arrow" /><span>{{ $fontName($c['proposed']) }}</span>
                                        @else
                                            <span class="br-chip__sw" style="--c: {{ $c['current'] ?: 'transparent' }}"></span><x-ds.icon name="arrow-right" size="sm" class="br-arrow" /><span class="br-chip__sw" style="--c: {{ $c['proposed'] }}"></span>
                                        @endif
                                        <span class="lt-meta">on {{ $many }} {{ \Illuminate\Support\Str::plural('element', $many) }}</span>
                                        <details class="br-sum__more">
                                            <summary>{{ $many === 1 ? 'Which one' : 'Which ones' }}</summary>
                                            <ul>@foreach ($group as $item)<li>{{ $item['element_name'] }}, page {{ $item['page_number'] }}</li>@endforeach</ul>
                                        </details>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @php
                            $other = collect((array) ($n['unused']['colors'] ?? []))->map(fn ($c) => ($c['name'] ?? 'Colour').(empty($c['from']) ? ' added' : (empty($c['to']) ? ' removed' : '')))
                                ->merge(collect((array) ($n['unused']['fonts'] ?? []))->map(fn ($f) => ($slotName[$f['slot']] ?? 'Font').': '.$fontName($f['from'] ?? '').' to '.$fontName($f['to'] ?? '')))
                                ->merge(collect((array) (($n['summary'] ?? [])['other'] ?? []))->map(fn ($o) => ucfirst($o).' changed'));
                        @endphp
                        @if ($other->isNotEmpty())
                            <p class="lt-meta br-notice__also">Also changed in the kit, not used in this design: {{ $other->implode(', ') }}.</p>
                        @endif
                    </div>
                    <div><x-ds.button :href="$n['review_url']" variant="secondary" size="sm">Review changes</x-ds.button></div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
</x-app-layout>
