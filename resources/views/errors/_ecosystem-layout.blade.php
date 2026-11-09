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

        // Every other Dot platform, from the shared ecosystem registry (config/ecosystem.php): add one there and it appears here.
        $currentPlatformName = 'Dot.Design';
        $discover = collect(config('ecosystem.platforms', []))
            ->reject(fn ($p) => ($p['name'] ?? null) === $currentPlatformName)
            ->reject(fn ($p) => ($p['active'] ?? true) === false)
            ->reject(fn ($p, $k) => ($p['internal'] ?? false) === true || in_array($k, ['memory', 'brain', 'dopemine'], true))
            ->values()
            ->all();
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

            @if (! empty($discover))
                <section aria-label="The rest of the Dot Ecosystem" style="width: 100%; padding-top: var(--lt-space-5); border-top: 1px solid var(--lt-rule); display: grid; gap: var(--lt-space-3);">
                    <p class="lt-small">While you are here: the rest of the Dot Ecosystem ({{ count($discover) }})</p>
                    <ul class="lt-strip" style="gap: var(--lt-space-2);">
                        @foreach ($discover as $platform)
                            <li><a href="{{ $platform['url'] }}" class="lt-chip"><span class="lt-swatch__chip" style="--c: {{ preg_match('/^#[0-9a-fA-F]{3,8}$/', $platform['accent'] ?? '') ? $platform['accent'] : 'transparent' }}; width: 0.875rem; height: 0.875rem;" aria-hidden="true"></span>{{ $platform['name'] }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="lt-meta">&copy; {{ date('Y') }} Dot.Design
                @if (Route::has('contact')) <a href="{{ route('contact') }}">Contact support</a> @endif
            </p>
        </div>
    </main>
</body>
</html>
