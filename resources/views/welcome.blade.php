@php
    $formats = \App\Support\Ui\StartFormats::all();
    $first = $formats[0];
    $isGuest = ! auth()->check();
    $hrefFor = fn (array $f) => $isGuest ? route('login') : route('design.projects.create').'?format='.$f['key'];
    $primaryHref = $isGuest ? route('login') : route('dashboard');
    // The artwork is real: library templates drawn by the editor's own renderer (see scripts/landing/build-art.mjs).
    $art = \App\Support\Ui\LandingArt::manifest();
    $heroMeta = $art[$first['key']] ?? null;
    $kitColours = [['#145a3c', 'Main colour'], ['#d4572a', 'Second colour'], ['#f0a030', 'Highlight colour'], ['#1f2933', 'Neutral']];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Dot.Design | Your ideas. Beautifully designed.</title>
    <meta name="description" content="Make posters, social posts, flyers and cards for your business on a canvas that already knows your colours, fonts and logo. Start from a size, share a link, get it approved.">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="{{ asset('fonts/ui/bricolage-grotesque-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="lp">
    <a class="lt-skip" href="#main">Skip to content</a>

    <header class="lp-wrap">
        <nav class="lp-nav" aria-label="Site">
            <a href="{{ url('/') }}" class="lt-brand" aria-label="Dot.Design, home" style="padding-left: 0;">
                <span class="lt-mark" role="img" aria-label="Dot.Design mark"></span>
                <span class="lt-brand__name">Dot.Design</span>
            </a>
            <div class="lp-nav__links">
                <a href="#how" class="lp-nav__link lp-nav__link--hide">How it works</a>
                <a href="#limits" class="lp-nav__link lp-nav__link--hide">What it does not do</a>
                @if (Route::has('login'))
                    <x-ds.button :href="$primaryHref" variant="secondary" size="sm">{{ $isGuest ? 'Log in' : 'Open Home' }}</x-ds.button>
                @endif
            </div>
        </nav>
    </header>

    <main id="main" tabindex="-1">
        <section class="lp-wrap lp-hero" aria-labelledby="hero-title">
            <div>
                <h1 id="hero-title" class="lp-h1">Your ideas. Beautifully designed.</h1>
                <p class="lp-lede">Posters, social posts, flyers and business cards, made on a canvas that already knows your colours, fonts and logo.</p>
                <div class="lp-actions">
                    <x-ds.button :href="$primaryHref" size="lg" iconEnd="arrow-right">{{ $isGuest ? 'Log in to start designing' : 'Open your designs' }}</x-ds.button>
                    <x-ds.button href="#how" variant="ghost" size="lg">See how it works</x-ds.button>
                </div>
            </div>

            <div class="lp-table" data-lp-table>
                <div class="lp-table__stage">
                    <figure class="lp-sheet lt-frame lt-crop @unless($heroMeta) lt-frame--blank @endunless" data-lp-sheet style="--w: {{ $heroMeta['width'] ?? $first['width'] }}; --h: {{ $heroMeta['height'] ?? $first['height'] }}; --crop-len: 12px; --crop-gap: 8px; --crop-c: var(--lt-paper-ink);">
                        <div class="lp-art" data-lp-art role="img" aria-label="{{ $heroMeta ? $heroMeta['name'].', a library template' : $first['name'] }}">{!! \App\Support\Ui\LandingArt::svg($first['key']) !!}</div>
                    </figure>
                </div>
                <div class="lp-proof">
                    <p class="lp-caption" data-lp-caption role="status">
                        @if ($heroMeta)<b data-lp-name>{{ $heroMeta['name'] }}</b>, a ready-made template, drawn by the same code the editor uses at {{ $first['width'] }} × {{ $first['height'] }} {{ $first['unit'] }}.@else<b data-lp-name>{{ $first['name'] }}</b>@endif
                    </p>
                    <ul class="lp-colors" data-lp-colors aria-label="Colours in this template">
                        @foreach (($heroMeta['colors'] ?? []) as $hex)<li style="--c: {{ $hex }}" title="{{ $hex }}"><span class="lt-sr">{{ $hex }}</span></li>@endforeach
                    </ul>
                </div>
                <div class="lp-table__ruler lt-ruler" aria-hidden="true" style="margin-top: 0;"></div>
                <div class="lp-formats" role="group" aria-label="Choose a size">
                    @foreach ($formats as $format)
                        @php($m = $art[$format['key']] ?? null)
                        <a href="{{ $hrefFor($format) }}" class="lt-fchip" data-lp-format data-key="{{ $format['key'] }}" data-name="{{ $format['name'] }}" data-w="{{ $m['width'] ?? $format['width'] }}" data-h="{{ $m['height'] ?? $format['height'] }}" data-size="{{ $format['width'] }} × {{ $format['height'] }} {{ $format['unit'] }}" data-href="{{ $hrefFor($format) }}" @if ($m) data-art="{{ \App\Support\Ui\LandingArt::url($format['key']) }}" data-art-name="{{ $m['name'] }}" data-colors="{{ implode(' ', $m['colors']) }}" @endif aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                            <span class="lt-fchip__frame" style="--w: {{ $format['width'] }}; --h: {{ $format['height'] }}" aria-hidden="true"></span>
                            <span><span class="lt-fchip__name">{{ $format['name'] }}</span><span class="lt-fchip__size">{{ $format['width'] }} × {{ $format['height'] }} {{ $format['unit'] }}</span></span>
                        </a>
                    @endforeach
                </div>
                <div class="lp-cta-line">
                    <p>Every design starts from a size. Pick one and see a real template at its true proportions.</p>
                    <x-ds.button :href="$hrefFor($first)" variant="secondary" data-lp-cta><span>{{ $isGuest ? 'Log in to start a' : 'Start a' }} <span data-lp-cta-text>{{ $first['name'] }}</span></span></x-ds.button>
                </div>
            </div>
        </section>

        <section id="how" class="lp-section lp-section--sunk" aria-labelledby="how-title">
            <div class="lp-wrap">
                <h2 id="how-title" class="lp-h2">From a size to a finished file</h2>
                <p class="lp-intro">Each step is optional except the first. You can skip ahead to a blank page at any point.</p>
                <ol class="lp-steps">
                    <li class="lp-step"><h3>Pick a size</h3><p>Social post, poster, flyer, card, or your own measurements. The canvas opens at that size.</p></li>
                    <li class="lp-step"><h3>Say what it is for</h3><p>A short brief points you to templates that fit. Skip it if you already know what you want.</p></li>
                    <li class="lp-step"><h3>Start from a template or a blank page</h3><p>A template is a real design you can edit, not a locked picture.</p></li>
                    <li class="lp-step"><h3>Edit on the canvas</h3><p>Text, shapes, pictures and icons, with guides that snap and undo that goes back as far as you need.</p></li>
                    <li class="lp-step"><h3>Export or share</h3><p>Download a file, or send a link someone can look at and comment on.</p></li>
                </ol>
            </div>
        </section>

        <section class="lp-section lp-section--deferred" aria-labelledby="brand-title">
            <div class="lp-wrap lp-brand">
                <div class="lp-brand__copy">
                    <h2 id="brand-title" class="lp-h2">Set your brand once</h2>
                    <p class="lp-intro">Add your colours, fonts and logo to a brand kit. New designs start from them. When the kit changes, you choose whether each design follows, so nothing is rewritten behind your back.</p>
                    <div class="lp-kitcard" aria-label="The example brand kit used on this page">
                        <p class="lp-kitcard__title">The example kit on the right</p>
                        <ul class="lp-kitcard__colors">
                            @foreach ($kitColours as [$hex, $label])<li><span class="lt-swatch__chip" style="--c: {{ $hex }}" aria-hidden="true"></span>{{ $label }} <span class="lt-swatch__value">{{ $hex }}</span></li>@endforeach
                        </ul>
                        <p class="lt-small">Heading font Fraunces, body font DM Sans.</p>
                    </div>
                </div>
                <figure class="lp-ba" aria-label="One template before and after an example brand kit is applied">
                    <div class="lp-ba__pair @if (($art['brand-before']['width'] ?? 1) > ($art['brand-before']['height'] ?? 1) * 1.2) lp-ba__pair--stack @endif">
                        @foreach (['brand-before' => 'As the template ships', 'brand-after' => 'With the example kit applied'] as $key => $caption)
                            @php($bm = $art[$key] ?? null)
                            <div class="lp-ba__cell">
                                <span class="lt-frame lt-crop @unless($bm) lt-frame--blank @endunless" style="--w: {{ $bm['width'] ?? 4 }}; --h: {{ $bm['height'] ?? 5 }}; --crop-len: 8px; --crop-gap: 6px; --crop-c: var(--lt-paper-ink);" role="img" aria-label="{{ $caption }}"><span class="lp-art">{!! \App\Support\Ui\LandingArt::svg($key) !!}</span></span>
                                <span class="lp-ba__cap">{{ $caption }}</span>
                            </div>
                            @if ($loop->first)<span class="lp-ba__arrow" aria-hidden="true"><x-ds.icon name="arrow-right" /></span>@endif
                        @endforeach
                    </div>
                    <figcaption class="lt-small">The same template, drawn by the same renderer. Only the brand changed: the words, the layout and the pictures are as they were.</figcaption>
                </figure>
            </div>
        </section>

        <section class="lp-section lp-section--sunk" aria-labelledby="modes-title">
            <div class="lp-wrap">
                <h2 id="modes-title" class="lp-h2">Simple when you are in a hurry, Pro when you are not</h2>
                <div class="lp-pair">
                    <div class="lp-mode"><h3>Simple</h3><p>One panel at a time, plain words, and the next sensible step suggested. Made for the person who has a shop to run.</p></div>
                    <div class="lp-mode"><h3>Pro</h3><p>Layers, alignment, exact measurements and every panel at once. Made for the person who knows what they want.</p></div>
                </div>
                <p class="lp-note">It is the same design either way. Switching modes changes what you see, never what is saved.</p>
            </div>
        </section>

        <section class="lp-section" aria-labelledby="share-title">
            <div class="lp-wrap lp-split">
                <h2 id="share-title" class="lp-h2">Share it, review it, approve it</h2>
                <div class="lp-facts">
                    <div class="lp-fact"><h3>A link with limits</h3><p>Send a link that only views, or one that also takes comments. Give it an end date, and switch it off whenever you like.</p></div>
                    <div class="lp-fact"><h3>Comments on the design itself</h3><p>Leave a note on a page or on one element, and mark it resolved when it is dealt with.</p></div>
                    <div class="lp-fact"><h3>Approval that stays honest</h3><p>Ask a colleague to review a version. Nobody approves their own request in a team, and if the design changes afterwards, it needs approving again.</p></div>
                </div>
            </div>
        </section>

        <section id="limits" class="lp-section lp-section--sunk" aria-labelledby="limits-title">
            <div class="lp-wrap lp-split">
                <h2 id="limits-title" class="lp-h2">What it does not do</h2>
                <div>
                    <p class="lp-intro" style="margin-top: 0;">Dot.Design does not post to social networks for you, sell stock photos, or let two people type in the same design at the same moment.</p>
                    <p class="lp-intro">It works with the pictures you upload, your brand assets, and the shapes and icons that come with it. The assistant only suggests changes. Nothing is applied until you accept it.</p>
                </div>
            </div>
        </section>

        <section class="lp-wrap lp-close lp-section--deferred" aria-labelledby="close-title">
            <div>
                <h2 id="close-title" class="lp-h1">Your ideas. Beautifully designed.</h2>
                <div class="lp-actions">
                    <x-ds.button :href="$primaryHref" size="lg" iconEnd="arrow-right">{{ $isGuest ? 'Log in to start designing' : 'Open your designs' }}</x-ds.button>
                </div>
            </div>
            <div class="lp-close__table" aria-hidden="true">
                @foreach (['instagram-post', 'poster', 'presentation'] as $key)
                    @php($cm = $art['close-'.$key] ?? null)
                    <span class="lt-frame lt-crop lp-art @unless($cm) lt-frame--blank @endunless" style="--w: {{ $cm['width'] ?? 1 }}; --h: {{ $cm['height'] ?? 1 }}; --crop-len: 8px; --crop-gap: 6px; --crop-c: var(--lt-paper-ink);">{!! \App\Support\Ui\LandingArt::svg('close-'.$key) !!}</span>
                @endforeach
            </div>
        </section>
    </main>

    <footer class="lp-wrap lp-foot">
        <div class="lt-ruler" aria-hidden="true"></div>
        <div class="lp-foot__row">
            <a href="{{ url('/') }}" class="lt-brand" aria-label="Dot.Design, home" style="padding-left: 0;"><span class="lt-mark" role="img" aria-label="Dot.Design mark"></span><span class="lt-brand__name">Dot.Design</span></a>
            <nav class="lp-foot__links" aria-label="Legal">
                <a href="{{ route('policy.show') }}">Privacy</a>
                <a href="{{ route('cookies') }}">Cookies</a>
                <a href="{{ route('terms.show') }}">Terms</a>
                @if (Route::has('login') && $isGuest)<a href="{{ route('login') }}">Log in</a>@endif
            </nav>
            <p class="lt-meta">&copy; {{ date('Y') }} Dot.Design</p>
        </div>
    </footer>
</div>
<x-ds.toast />
</body>
</html>
