@php
    $boot = [
        'groups' => collect($groups)->map(fn ($g) => ['key' => $g['key'], 'label' => $g['label'], 'help' => $g['help'], 'formats' => collect($g['formats'])->map(fn ($f) => [
            'key' => $f['key'], 'label' => $f['label'], 'use' => $f['use'], 'size_label' => $f['size_label'], 'width' => $f['px']['width'], 'height' => $f['px']['height'], 'kind' => $f['kind'], 'platform' => $f['platform'],
        ])->all()])->all(),
        'limits' => $limits,
        'fonts' => $fonts,
        'urls' => $urls,
        'assistant' => $assistant,
        'goals' => collect($goals)->map(fn ($g) => ['key' => $g['key'], 'purpose' => $g['purpose']])->all(),
        'preset_custom' => ($preset['kind'] ?? null) === 'custom' ? $preset : null,
    ];
    $presetFormat = ($preset['kind'] ?? null) === 'format' ? $preset['format'] : null;
    $query = $presetFormat ? ['format' => $presetFormat['key']] : [];
    $step = collect(config('design_brief_flow.steps'))->firstWhere('key', 'goal');
@endphp
<x-app-layout>
@push('head')
    @vite(['resources/js/studio/pages/start.js'])
@endpush

<div class="lt-page cf-start" data-cf-start>
    <x-ds.page-header :title="$step['title']" :sub="$step['help']">
        <x-ds.button variant="secondary" icon="plus" data-lt-dialog-open="size-picker" data-cf-picker-open>Open a blank canvas</x-ds.button>
    </x-ds.page-header>

    <x-ds.milestone :milestone="app(\App\Support\Ui\Milestones::class)->pending(auth()->user())" />

    @if ($errors->any())
        <div class="lt-alert lt-alert--bad" role="alert" style="margin-bottom: var(--lt-space-5);">
            <x-ds.icon name="circle-alert" />
            <div><strong>That design could not be created.</strong>
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        </div>
    @endif

    @if ($presetFormat)
        <section class="cf-preset" aria-labelledby="preset-title">
            <div class="cf-preset__stage"><x-ds.frame :width="$presetFormat['px']['width']" :height="$presetFormat['px']['height']" class="lt-crop lt-crop--quiet" /></div>
            <div class="cf-preset__body">
                <h2 id="preset-title" class="lt-h2">{{ $presetFormat['label'] }}</h2>
                <p class="lt-small">{{ $presetFormat['size_label'] }}. {{ $presetFormat['use'] }}.</p>
                <div class="cf-preset__actions">
                    <form method="POST" action="{{ route('design.projects.store') }}">
                        @csrf
                        <input type="hidden" name="format_key" value="{{ $presetFormat['key'] }}">
                        <x-ds.button type="submit" data-cf-busy>Open a blank canvas</x-ds.button>
                    </form>
                    <x-ds.button :href="route('design.templates.index', ['format' => $presetFormat['key']])" variant="secondary">Pick a starting design</x-ds.button>
                    <x-ds.button :href="route('design.start')" variant="ghost">Choose another size</x-ds.button>
                </div>
            </div>
        </section>
    @elseif (($preset['kind'] ?? null) === 'custom')
        <section class="cf-preset" aria-labelledby="preset-title">
            <div class="cf-preset__stage"><x-ds.frame :width="$preset['width']" :height="$preset['height']" class="lt-crop lt-crop--quiet" /></div>
            <div class="cf-preset__body">
                <h2 id="preset-title" class="lt-h2">{{ $preset['label'] }}</h2>
                <p class="lt-small">{{ $preset['width'] }} x {{ $preset['height'] }} {{ $preset['unit'] }}. Change the size in the blank canvas picker if you need another one.</p>
                <div class="cf-preset__actions">
                    <form method="POST" action="{{ route('design.projects.store') }}">
                        @csrf
                        <input type="hidden" name="name" value="{{ $preset['label'] }}">
                        <input type="hidden" name="type" value="logo">
                        <input type="hidden" name="width" value="{{ $preset['width'] }}">
                        <input type="hidden" name="height" value="{{ $preset['height'] }}">
                        <input type="hidden" name="unit" value="{{ $preset['unit'] }}">
                        <x-ds.button type="submit" data-cf-busy>Open a blank canvas</x-ds.button>
                    </form>
                    <x-ds.button :href="route('design.start')" variant="ghost">Choose another size</x-ds.button>
                </div>
            </div>
        </section>
    @endif

    @if ($assistant)
        <section class="cf-describe" aria-labelledby="describe-title" data-cf-describe hidden>
            <h2 id="describe-title" class="lt-h3">Describe your idea</h2>
            <p class="lt-small">Tell us what you want in a sentence or two. We make a first layout that you can change.</p>
            <form class="cf-describe__form" novalidate>
                <label for="describe-prompt" class="lt-sr">Your idea</label>
                <textarea id="describe-prompt" class="lt-textarea" rows="3" maxlength="600" placeholder="A poster for our winter pie special, 20% off, every Saturday"></textarea>
                <div class="cf-describe__row">
                    <x-ds.button type="submit">Create a first layout</x-ds.button>
                    <p class="lt-meta" data-cf-describe-hint>Takes up to half a minute.</p>
                </div>
            </form>
            <div data-cf-describe-out aria-live="polite"></div>
        </section>
    @endif

    <section class="lt-section cf-goals-section" aria-labelledby="goals-title">
        <div class="lt-section__head">
            <h2 id="goals-title" class="lt-section__title">I want to make something for</h2>
            <p class="lt-small">Each choice asks a few short questions and shows designs to start from.</p>
        </div>
        <ul class="cf-goals" role="list">
            @foreach ($goals as $goal)
                <li>
                    <a class="cf-goal" href="{{ route('design.start.brief', ['goal' => $goal['key']] + $query) }}" data-goal="{{ $goal['key'] }}" data-purpose="{{ $goal['purpose'] }}">
                        <span class="cf-goal__stage" aria-hidden="true"><span class="cf-goal__frame lt-frame lt-frame--blank" style="--w: 3; --h: 4"></span></span>
                        <span class="cf-goal__label">{{ $goal['label'] }}</span>
                        <span class="cf-goal__help">{{ $goal['help'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="lt-section" aria-labelledby="blank-title">
        <div class="lt-section__head">
            <h2 id="blank-title" class="lt-section__title">Or start with a blank canvas</h2>
            <p class="lt-small">One click opens the editor at that size.</p>
        </div>
        <form method="POST" action="{{ route('design.projects.store') }}" class="cf-chips" role="list" aria-label="Common sizes">
            @csrf
            @foreach ($common as $format)
                <button type="submit" name="format_key" value="{{ $format['key'] }}" role="listitem" class="lt-fchip" title="{{ $format['label'] }}: {{ $format['size_label'] }}. {{ $format['use'] }}">
                    <span class="lt-fchip__frame" style="--w: {{ $format['px']['width'] }}; --h: {{ $format['px']['height'] }}" aria-hidden="true"></span>
                    <span>
                        <span class="lt-fchip__name">{{ $format['label'] }}</span>
                        <span class="lt-fchip__size">{{ $format['size_label'] }}</span>
                    </span>
                </button>
            @endforeach
            <button type="button" role="listitem" class="lt-fchip cf-chips__more" data-lt-dialog-open="size-picker" data-cf-picker-open>
                <span class="lt-fchip__frame cf-chips__plus" aria-hidden="true"><x-ds.icon name="plus" /></span>
                <span>
                    <span class="lt-fchip__name">More sizes</span>
                    <span class="lt-fchip__size">or your own</span>
                </span>
            </button>
        </form>
        <div class="lt-ruler lt-start__rule" aria-hidden="true"></div>
    </section>

    <section class="lt-section" aria-labelledby="recent-title">
        <div class="lt-section__head">
            <h2 id="recent-title" class="lt-section__title">Pick up where you left off</h2>
            @if ($recent->isNotEmpty())<a href="{{ route('design.projects.index') }}" class="lt-small" style="text-decoration: underline; text-underline-offset: 0.2em;">View all designs</a>@endif
        </div>
        @if ($recent->isEmpty())
            <p class="lt-small cf-quiet">Your designs will appear here once you make one.</p>
        @else
            <div class="lt-contacts">
                @foreach ($recent as $project)
                    <x-ds.design-tile :project="$project" :brand-updated="in_array($project->id, $brandUpdated, true)" />
                @endforeach
            </div>
        @endif
        <p class="lt-small cf-quiet" style="margin-top: var(--lt-space-5);">Prefer to browse? <a href="{{ route('design.templates.index') }}">See all ready-made designs</a>.</p>
    </section>

    <x-ds.dialog id="size-picker" title="Open a blank canvas" wide>
        <div data-lt-tabs data-cf-picker>
            <div class="lt-tabs" role="tablist" aria-label="Canvas size">
                <button type="button" role="tab" id="sz-sizes" class="lt-tab" aria-controls="sz-panel-sizes" aria-selected="true" tabindex="0">Standard sizes</button>
                <button type="button" role="tab" id="sz-custom" class="lt-tab" aria-controls="sz-panel-custom" aria-selected="false" tabindex="-1">Custom size</button>
            </div>

            <div role="tabpanel" id="sz-panel-sizes" aria-labelledby="sz-sizes" class="lt-tabpanel cf-picker__panel">
                <div class="cf-picker__search">
                    <label for="size-search" class="lt-sr">Search sizes</label>
                    <div class="lt-input-wrap"><x-ds.icon name="search" /><input id="size-search" type="search" class="lt-input" placeholder="Search, for example A4 or story" autocomplete="off" data-cf-size-search></div>
                </div>
                <form method="POST" action="{{ route('design.projects.store') }}" class="cf-picker__list" data-cf-size-list>
                    @csrf
                    @foreach ($groups as $group)
                        <section class="cf-picker__group" data-group aria-labelledby="grp-{{ $group['key'] }}">
                            <h3 id="grp-{{ $group['key'] }}" class="cf-picker__heading">{{ $group['label'] }}<span class="lt-meta">{{ $group['help'] }}</span></h3>
                            <ul role="list">
                                @foreach ($group['formats'] as $format)
                                    <li data-format data-search="{{ \Illuminate\Support\Str::lower($format['label'].' '.$format['use'].' '.$format['size_label'].' '.($format['platform'] ?? '').' '.$group['label']) }}">
                                        <button type="submit" name="format_key" value="{{ $format['key'] }}" class="cf-picker__row">
                                            <span class="cf-picker__frame" style="--w: {{ $format['px']['width'] }}; --h: {{ $format['px']['height'] }}" aria-hidden="true"></span>
                                            <span class="cf-picker__text"><span class="cf-picker__name">{{ $format['label'] }}</span><span class="cf-picker__use">{{ $format['use'] }}</span></span>
                                            <span class="cf-picker__size">{{ $format['size_label'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                    <p class="cf-picker__none lt-small" data-cf-size-none hidden>No size matches that. Try another word, or use a custom size.</p>
                </form>
            </div>

            <div role="tabpanel" id="sz-panel-custom" aria-labelledby="sz-custom" class="lt-tabpanel cf-picker__panel" hidden>
                <form method="POST" action="{{ route('design.projects.store') }}" class="cf-custom" data-cf-custom novalidate>
                    @csrf
                    <input type="hidden" name="type" value="graphic">
                    <input type="hidden" name="unit" value="px">
                    <input type="hidden" name="width" data-cf-px-w>
                    <input type="hidden" name="height" data-cf-px-h>
                    <input type="hidden" name="name" data-cf-name>
                    <div class="cf-custom__row">
                        <div class="lt-field"><label for="custom-w" class="lt-label">Width</label><input id="custom-w" class="lt-input" type="number" inputmode="decimal" step="any" min="0" value="1080" data-cf-w aria-describedby="custom-msg"></div>
                        <button type="button" class="lt-icon-btn lt-icon-btn--outline cf-custom__swap" aria-label="Swap width and height" data-tip="Swap width and height" data-cf-swap><x-ds.icon name="refresh" /></button>
                        <div class="lt-field"><label for="custom-h" class="lt-label">Height</label><input id="custom-h" class="lt-input" type="number" inputmode="decimal" step="any" min="0" value="1080" data-cf-h aria-describedby="custom-msg"></div>
                        <div class="lt-field"><label for="custom-u" class="lt-label">Unit</label>
                            <select id="custom-u" class="lt-select" data-cf-unit>
                                <option value="px">px (pixels)</option>
                                <option value="mm">mm</option>
                                <option value="cm">cm</option>
                                <option value="in">in (inches)</option>
                            </select>
                        </div>
                    </div>
                    <div class="cf-custom__readout">
                        <span class="cf-custom__frame"><span class="lt-frame" style="--w: 1" data-cf-frame></span></span>
                        <p id="custom-msg" class="cf-custom__msg" role="status" aria-live="polite" data-cf-readout></p>
                    </div>
                    <p class="lt-hint">The canvas works in pixels at 72 per inch, so 210 x 297 mm becomes 595 x 842 px. Print exports are made larger from that. Sizes run from {{ $limits['min_px'] }} to {{ number_format($limits['max_px'], 0, '.', ' ') }} px on each side.</p>
                    <div class="lt-form__actions"><x-ds.button type="submit" data-cf-custom-submit>Open canvas</x-ds.button></div>
                </form>
            </div>
        </div>
    </x-ds.dialog>

    <script type="application/json" id="start-boot">{!! \Illuminate\Support\Js::encode($boot) !!}</script>
</div>
</x-app-layout>
