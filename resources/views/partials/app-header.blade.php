@php
    /* The shared dot-ui header for every signed-in surface (the app shell and the editor). $nav is Navigation::build(), $barTitle the page's own short title (desktop only). */
    $user = auth()->user();
    $team = $user?->currentTeam;
    $account = $user ? \App\Support\Ui\Shell::account($user, request()) : null;
    $waiting = $user ? \App\Support\Ui\Shell::waitingReviews($user) : 0;
    $reviewsRoute = \Illuminate\Support\Facades\Route::has('design.reviews.index') ? route('design.reviews.index') : null;
    $searchRoute = $user && \Illuminate\Support\Facades\Route::has('design.search') ? route('design.search') : null;
    $barTitle = $barTitle ?? '';
@endphp
    <x-dot.header
    id="dh" :skip="$skip ?? '#main'"
    brand-href="{{ route('dashboard') }}" brand-label="Dot.Design, home"
    :account="$account"
    :context="$team?->name"
    drawer-title="Menu">
    <x-slot:brand>
        <img class="dh-logo-day" src="{{ asset('images/logo.png') }}" alt="" width="1181" height="886" decoding="async" fetchpriority="high">
        <img class="dh-logo-night" src="{{ asset('images/logo-light.png') }}" alt="" width="1181" height="886" loading="lazy" decoding="async">
    </x-slot:brand>
    @if ($barTitle !== '')
        <x-slot:status><span class="dh-status__text lt-barstatus">{{ $barTitle }}</span></x-slot:status>
    @endif
    @if ($searchRoute)
        <x-slot:search>
            <x-dot.search
                name="q" :action="$searchRoute" :value="request()->routeIs('design.search') ? \App\Services\Design\WorkspaceSearch::clean(request()->query('q')) : ''"
                placeholder="Search designs, brand kits, assets" label="Search Dot.Design" hint="Ctrl K"
                loading-text="Looking in this workspace..." escape-clears results-nav
                :root-attributes="['data-ds-search' => $searchRoute]" />
        </x-slot:search>
    @endif
    <x-slot:tools>
        @if ($user)
            <x-dot.switcher platform="design" :model="\App\Support\Ui\Shell::switcher($team?->infodot_team_uuid)" :company-name="$team?->name" logos="/dot-ui/img/platforms" />
            @if ($reviewsRoute)
                <x-dot.bell :href="$reviewsRoute" :count="$waiting" label="Reviews waiting for you" :always="true" />
            @endif
        @endif
    </x-slot:tools>
    {{-- The phone menu: the same destinations as the rail and the tab bar, then the account section the header adds by itself. --}}
    <x-slot:drawer>
        <nav class="dh-dnav" aria-label="Destinations">
            <ul>
                @foreach ($nav['groups'] as $group)
                    @if ($group['key'] === 'studio')
                        @foreach ($group['items'] as $item)
                            <li><a class="dh-dnav__link" href="{{ $item['href'] }}" @if($item['current']) aria-current="page" @endif><span class="dh-menu__label">{{ $item['label'] }}</span></a></li>
                        @endforeach
                    @else
                        @php $open = collect($group['items'])->contains(fn ($i) => $i['current']); @endphp
                        <li class="dh-dgroup">
                            <button type="button" class="dh-dgroup__btn" data-dh-disclosure aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="dh-drawer-{{ $group['key'] }}"><span class="dh-menu__label">{{ $group['label'] }}</span><span class="dh-chev" aria-hidden="true"></span></button>
                            <ul id="dh-drawer-{{ $group['key'] }}" class="dh-dgroup__list" @unless($open) hidden @endunless>
                                @foreach ($group['items'] as $item)
                                    <li><a class="dh-dnav__link" href="{{ $item['href'] }}" @if($item['current']) aria-current="page" @endif><span class="dh-menu__label">{{ $item['label'] }}</span></a></li>
                                @endforeach
                            </ul>
                        </li>
                    @endif
                @endforeach
            </ul>
        </nav>
    </x-slot:drawer>
</x-dot.header>
