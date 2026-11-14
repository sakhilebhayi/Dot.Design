<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $code }}: @yield('title') | Dot.Design</title>
    <meta name="robots" content="noindex">
    @foreach (['favicon.ico', 'favicon-32x32.png', 'favicon-16x16.png'] as $faviconCandidate)
        @if (file_exists(public_path($faviconCandidate)))
            <link rel="icon" href="{{ asset($faviconCandidate) }}">
            @break
        @endif
    @endforeach
    <script src="{{ asset('js/ds-theme.js') }}"></script>
    @php
        // Error pages must render even if the asset build is missing, so only ask Vite for entries the manifest has.
        $viteManifestPath = public_path('build/manifest.json');
        $viteEntries = [];
        if (file_exists($viteManifestPath)) {
            $viteManifest = json_decode(file_get_contents($viteManifestPath), true) ?? [];
            foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
                if (isset($viteManifest[$entry])) {
                    $viteEntries[] = $entry;
                }
            }
        }

        // Where to go next: at most three places that are useful from an error, never a directory of every platform.
        $signedIn = false;
        try { $signedIn = auth()->check(); } catch (\Throwable $e) { /* maintenance mode and early failures have no session */ }
        $destinations = [];
        if ($hideNext ?? false) {
            // A page that must not point back into the studio (the company does not hold the platform) names its own next steps.
        } elseif ($signedIn) {
            foreach ([['design.projects.index', 'Your designs'], ['design.templates.index', 'Templates'], ['design.brand.index', 'Brand']] as [$routeName, $label]) {
                if (Route::has($routeName)) { $destinations[] = ['url' => route($routeName), 'label' => $label]; }
            }
        } elseif (Route::has('login')) {
            $destinations[] = ['url' => route('login'), 'label' => 'Log in'];
        }
    @endphp
    @if (! empty($viteEntries))
        @vite($viteEntries)
    @endif
</head>
<body>
    <a class="lt-skip" href="#main">Skip to content</a>
    <main id="main" class="lt-errorpage">
        <div class="lt-errorpage__box">
            <a href="/" class="lt-brand" aria-label="Dot.Design, home" style="padding-left: 0;">
                <span class="lt-mark" role="img" aria-label="Dot.Design mark"></span>
                <span class="lt-brand__name">Dot.Design</span>
            </a>

            <p class="lt-errorcode" aria-hidden="true">{{ $code }}</p>
            <div style="display: grid; gap: var(--lt-space-3);">
                <h1 class="lt-auth__title">@yield('title')</h1>
                <p class="lt-empty__text" style="max-width: 46ch;">@yield('message')</p>
            </div>

            <div class="lt-form__actions">
                @yield('actions')
            </div>

            @yield('after')

            @if (! empty($destinations))
                <nav aria-label="Other places to go" class="lt-errorpage__next">
                    <p class="lt-small">Or go to</p>
                    <ul>
                        @foreach ($destinations as $destination)
                            <li><a href="{{ $destination['url'] }}">{{ $destination['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <p class="lt-meta">&copy; {{ date('Y') }} Dot.Design
                @if (Route::has('contact')) <a href="{{ route('contact') }}">Contact support</a> @endif
            </p>
        </div>
    </main>
</body>
</html>
