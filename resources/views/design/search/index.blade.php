@php
    request()->attributes->set('page_title', $q !== '' ? 'Search: '.$q : 'Search');
    $total = array_sum(array_column($groups, 'total'));
@endphp
<x-app-layout>
<div class="lt-page lt-page--narrow" data-search-page>
    <x-ds.page-header title="Search" :sub="$q === '' ? 'Designs, brand kits, assets, folders and templates in your workspaces.' : null" />

    @if ($q === '')
        <x-ds.empty title="What are you looking for?" icon="search">
            Type a name in the search box at the top of the page. It looks through the designs, brand kits, assets, folders and templates you can open.
        </x-ds.empty>
    @elseif ($short)
        <x-ds.empty title="Type a little more" icon="search">Search needs at least {{ \App\Services\Design\WorkspaceSearch::MIN_LENGTH }} letters.</x-ds.empty>
    @elseif ($groups === [])
        <x-ds.empty title="Nothing matches &quot;{{ $q }}&quot;" icon="search">
            Nothing you can open has that name. Check the spelling, try a shorter word, or look through <a href="{{ route('design.projects.index') }}">all designs</a>.
        </x-ds.empty>
    @else
        <p class="lt-small" data-search-count>{{ $total }} {{ \Illuminate\Support\Str::plural('result', $total) }} for &quot;{{ $q }}&quot;</p>
        @foreach ($groups as $group)
            <section class="lt-section" aria-labelledby="sr-{{ $group['key'] }}">
                <div class="lt-section__head">
                    <h2 class="lt-section__title" id="sr-{{ $group['key'] }}">{{ $group['head'] }} <span class="lt-meta">{{ $group['total'] }}</span></h2>
                    @if ($group['more'])<a href="{{ $group['more'] }}">See all {{ $group['total'] }}</a>@endif
                </div>
                <ul class="sr-list">
                    @foreach ($group['rows'] as $row)
                        <li><a class="sr-row" href="{{ $row['href'] }}"><span class="sr-row__label">{{ $row['label'] }}</span>@if ($row['tag'])<span class="sr-row__tag lt-meta">{{ $row['tag'] }}</span>@endif</a></li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
</x-app-layout>
