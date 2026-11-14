@php
    use App\Support\Ui\ProjectCard;
    use App\Support\Ui\DesignListing;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $user = auth()->user();
    $team = $user?->currentTeam;
    $rows = $home['rows'];
    $selected = $home['selected'];
    $counts = $home['counts'];
    $waiting = $home['waiting'];
    $brand = $home['brand'];
    $canCreate = $home['canCreate'];
    $formats = $canCreate ? \App\Support\Ui\StartFormats::all() : [];
    $brandRoute = collect(['design.brand.index', 'brand.index', 'design.brand-kits.index'])->first(fn ($name) => RouteFacade::has($name));
    $assetRoute = collect(['design.assets.index', 'assets.index'])->first(fn ($name) => RouteFacade::has($name));
    $milestone = app(\App\Support\Ui\Milestones::class)->pending($user);
    $isAdmin = $user && $team && RouteFacade::has('design.activity.page') && in_array(\App\Support\Design\DesignAccess::teamRole($user->id, $team->id), ['owner', 'admin'], true);
    $proofs = $rows->take(3)->values(); // the real saved proofs shown in the arrival band; a design with no saved thumbnail draws as a neutral blank sheet

    // One primary action per page, chosen for this person in this state: creators start a design, a reviewer is taken to what
    // they were asked to look at, and anyone else to the designs they can open.
    if ($canCreate) {
        $primary = ['label' => 'Start a design', 'href' => route('design.start'), 'icon' => 'plus'];
    } elseif ($home['assigned'] > 0) {
        $primary = ['label' => 'Open reviews', 'href' => route('design.reviews.index'), 'icon' => 'check'];
    } else {
        $primary = ['label' => 'Browse designs', 'href' => route('design.projects.index'), 'icon' => 'layers'];
    }
    $quiet = $canCreate && $home['assigned'] > 0 ? ['label' => 'Open reviews', 'href' => route('design.reviews.index')] : null;

    request()->attributes->set('page_title', 'Home'); // the browser tab keeps the name the navigation uses
    request()->attributes->set('bar_title', 'Home');  // and the bar of the sheet carries it at 16px
@endphp
<x-app-layout>
<div class="lt-page lt-home-page" data-home>

    {{-- The arrival: one true sentence about what is waiting, the workspace in a line, the real proofs on the table, and the one
         primary action of this page. The logo lives in the rail and the phone bar only. --}}
    <header class="lt-arrive">
        <div class="lt-arrive__text">
            <h1 class="lt-display lt-arrive__title">{{ $home['headline'] }}</h1>
            <p class="lt-arrive__lead">{{ $home['subline'] }}</p>
        </div>

        <div class="lt-arrive__proofs" aria-hidden="true">
            @if ($proofs->isEmpty())
                <span class="lt-arrive__sheet lt-arrive__sheet--mark" style="--i: 0"><span class="lt-arrive__blank lt-crop"><x-ds.icon name="registration" size="lg" /></span></span>
            @else
                @foreach ($proofs as $proof)
                    <span class="lt-arrive__sheet" style="--i: {{ $loop->index }}"><x-ds.frame :width="$proof->width" :height="$proof->height" :src="ProjectCard::thumbnailUrl($proof)" loading="eager" class="lt-crop lt-crop--quiet" /></span>
                @endforeach
            @endif
        </div>

        <div class="lt-arrive__act">
            <x-ds.button :href="$primary['href']" :icon="$primary['icon']" size="lg" data-primary-action>{{ $primary['label'] }}</x-ds.button>
            @if ($quiet)<a class="lt-arrive__link" href="{{ $quiet['href'] }}">{{ $quiet['label'] }}</a>@endif
        </div>
    </header>

    <x-ds.milestone :milestone="$milestone" />

    @if ($waiting)
    <section class="lt-waiting" aria-labelledby="waiting-title">
        <div class="lt-hsec"><h2 id="waiting-title">Waiting on you</h2></div>
        <ul class="lt-waitrows">
            @foreach ($waiting as $item)
                <li style="--i: {{ $loop->index }}">
                    <span class="lt-waitrows__kind">{{ $item['kind'] }}</span>
                    <span class="lt-waitrows__main"><a href="{{ $item['href'] }}">{{ $item['title'] }}</a><small>{{ $item['note'] }}</small></span>
                    @if ($item['when'])<span class="lt-waitrows__when">{{ $item['when'] }}</span>@endif
                </li>
            @endforeach
        </ul>
        @if ($home['assigned'] > \App\Support\Ui\HomeSummary::WAITING_ROWS)
            <p class="lt-waiting__more"><a class="lt-arrive__more" href="{{ route('design.reviews.index') }}">All reviews</a></p>
        @endif
    </section>
    @endif

    @if ($rows->isEmpty())
        <section class="lt-desk lt-desk--empty" aria-labelledby="led-title">
            <div class="lt-hsec"><h2 id="led-title">Designs</h2></div>
            <div class="lt-empty" data-empty-state>
                <span class="lt-empty__stage" aria-hidden="true"><span class="lt-arrive__blank lt-crop"><x-ds.icon name="registration" size="lg" /></span></span>
                <div>
                    <h3>Nothing on the table yet</h3>
                    <p>@if ($canCreate)Your first design will sit here at its true proportions. <a href="{{ route('design.templates.index') }}">Start from a template</a> if you would rather not begin with a blank page.@else Designs shared with you, or sent to you for review, will be listed here.@endif</p>
                </div>
            </div>
            @if ($formats)
                <div class="lt-sizes">
                    <h3>Start at a size</h3>
                    <div class="lt-sizes__chips" role="group" aria-label="Sizes">
                        @foreach ($formats as $format)
                            <x-ds.format-chip :href="route('design.start').'?format='.$format['key']" :name="$format['name']" :width="$format['width']" :height="$format['height']" :size="$format['width'].' × '.$format['height'].' '.$format['unit']" />
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @else
        <div class="lt-desk" data-home-desk>
            <section aria-labelledby="led-title" class="lt-desk__ledger">
                <div class="lt-hsec"><h2 id="led-title">Designs</h2><a class="lt-arrive__more" href="{{ route('design.projects.index') }}">All designs</a></div>
                <table class="lt-ledger" data-home-ledger>
                    <thead>
                        <tr>
                            <th class="lt-ledger__th"><span class="lt-sr">Preview</span></th>
                            <th aria-sort="none" data-sort-col="name"><button type="button" data-sort="name">Name</button></th>
                            <th class="lt-ledger__colst" aria-sort="none" data-sort-col="status"><button type="button" data-sort="status">Status</button></th>
                            <th class="lt-ledger__ed">Edited</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($chip = ProjectCard::status($row))
                            <tr style="--i: {{ $loop->index }}" data-name="{{ $row->name }}" data-status="{{ $chip['label'] ?? '' }}" @if ($selected && $row->id === $selected->id) aria-current="true" @endif>
                                <td class="lt-ledger__th"><span class="lt-ledger__thumb"><x-ds.frame :width="$row->width" :height="$row->height" :src="ProjectCard::thumbnailUrl($row)" /></span></td>
                                <td class="lt-ledger__nm">
                                    <a href="{{ route('dashboard', ['design' => $row->id]) }}#proof" data-design="{{ $row->id }}">{{ $row->name }}</a>
                                    <small>{{ DesignListing::sizeLabel($row) }}</small>
                                    <span class="lt-ledger__meta2">{{ $chip['label'] ?? 'Draft' }}, edited {{ $row->updated_at?->diffForHumans() ?? 'never' }}</span>
                                </td>
                                <td class="lt-ledger__st lt-ledger__colst">@if ($chip)<x-ds.tag :kind="$chip['kind']">{{ $chip['label'] }}</x-ds.tag>@endif</td>
                                <td class="lt-ledger__ed">{{ $row->updated_at?->diffForHumans() ?? 'never' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="lt-sr" role="status" data-home-status></p>

                @if ($formats)
                    <div class="lt-sizes">
                        <h3>Start at a size</h3>
                        <div class="lt-sizes__chips" role="group" aria-label="Sizes">
                            @foreach ($formats as $format)
                                <x-ds.format-chip :href="route('design.start').'?format='.$format['key']" :name="$format['name']" :width="$format['width']" :height="$format['height']" :size="$format['width'].' × '.$format['height'].' '.$format['unit']" />
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            @if ($selected)
            <aside class="lt-panel" id="proof" aria-labelledby="sel-title" data-home-panel>
                @include('partials.home-panel', ['project' => $selected, 'home' => $home])
            </aside>
            @endif
        </div>
    @endif

    <div class="lt-lower">
        <section aria-labelledby="brand-title">
            <div class="lt-hsec"><h2 id="brand-title">Brand</h2></div>
            @if ($brand)
                <div class="lt-brandrow">
                    <span class="lt-brandrow__name"><b>{{ $brand['name'] }}@if($brand['version']), version {{ $brand['version'] }}@endif</b>
                        @if ($brand['fonts'])<small>{{ implode(', ', array_slice($brand['fonts'], 0, 2)) }}</small>@endif
                        @if ($brand['outdated'] > 0)<small><a class="lt-arrive__more" href="{{ route('design.brand.updates') }}">{{ $brand['outdated'] }} {{ \Illuminate\Support\Str::plural('design', $brand['outdated']) }} to review</a></small>@endif
                    </span>
                    @if ($brand['colors'])<span class="lt-brandrow__sw">@foreach (array_slice($brand['colors'], 0, 4) as $hex)<x-ds.swatch :color="$hex" />@endforeach</span>@endif
                    @if ($brandRoute)<x-ds.button :href="route($brandRoute)" variant="secondary" size="sm">Open</x-ds.button>@endif
                </div>
            @else
                <div class="lt-brandrow">
                    <span class="lt-brandrow__name"><b>No brand kit yet.</b><small>A kit keeps your colours and type one click away in every design.</small></span>
                    @if ($brandRoute)<x-ds.button :href="route($brandRoute)" variant="secondary" size="sm">Set up</x-ds.button>@endif
                </div>
            @endif
        </section>

        <section aria-labelledby="assets-title">
            <div class="lt-hsec"><h2 id="assets-title">Recent assets</h2>@if ($assetRoute && $home['recentAssets']->isNotEmpty())<a class="lt-arrive__more" href="{{ route($assetRoute) }}">Open assets</a>@endif</div>
            @if ($home['recentAssets']->isEmpty())
                <p class="lt-small lt-lower__none">No assets yet. Pictures you add inside a design are kept here.</p>
            @else
                <ul class="lt-assetstrip">
                    @foreach ($home['recentAssets'] as $asset)
                        <li class="lt-asset" style="--i: {{ $loop->index }}" title="{{ $asset->name }}{{ $asset->isPlaceholder() ? ' (placeholder)' : '' }}">
                            @if ($asset->type === 'image' && $asset->displayUrl())
                                <img src="{{ $asset->displayUrl() }}" alt="{{ $asset->name }}" loading="lazy" width="88" height="88">
                            @else
                                <x-ds.icon name="page" size="lg" class="lt-faint" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <p class="lt-homefoot">
        <span><b>{{ $counts['designs'] }}</b> {{ \Illuminate\Support\Str::plural('design', $counts['designs']) }}</span>
        <span><b>{{ $counts['in_review'] }}</b> in review</span>
        <span><b>{{ $counts['approved'] }}</b> approved</span>
        <span><b>{{ $counts['assets'] }}</b> {{ \Illuminate\Support\Str::plural('asset', $counts['assets']) }}</span>
        @if ($isAdmin)<a class="lt-arrive__more" href="{{ route('design.activity.page', ['team_id' => $team->id]) }}">Activity</a>@endif
    </p>
</div>
</x-app-layout>
