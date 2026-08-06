<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Design — The Dot Ecosystem's visual creation platform</title>
        <meta name="description" content="A canvas-first design editor paired with generative AI, plus the shared token and component library the rest of the Dot Ecosystem draws its UI from.">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&family=Instrument+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #faf9f5;
                --panel: #ffffff;
                --ink: #221f2b;
                --ink-soft: #565269;
                --gold: #d99a1f;
                --gold-bright: #efb93a;
                --periwinkle: #6169b8;
                --periwinkle-soft: #8b93d6;
                --line: rgba(34, 31, 43, 0.12);
                --font-display: 'Fraunces', ui-serif, Georgia, serif;
                --font-body: 'Instrument Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-motion: no-preference) {}
            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
                .draw-path {
                    stroke-dasharray: 900;
                    stroke-dashoffset: 900;
                    transition: stroke-dashoffset 1400ms var(--ease-out);
                }
                .draw-path.is-visible { stroke-dashoffset: 0; }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
                .draw-path { stroke-dashoffset: 0; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(34, 31, 43, 0.025); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#faf9f5]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Design" class="h-14 sm:h-[4.5rem] w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#canvas" class="link-underline hover:text-[var(--ink)] pb-0.5">Canvas</a>
                    <a href="#system" class="link-underline hover:text-[var(--ink)] pb-0.5">Design system</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--periwinkle)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--periwinkle)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#canvas" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Canvas</a>
                    <a href="#system" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Design system</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative pt-32 pb-16 sm:pb-24 px-5 sm:px-8 overflow-hidden">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                            Visual creation platform
                        </p>
                        <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.06] tracking-tight text-[var(--ink)] mb-6">
                            One canvas.<br>Every asset your<br>team ships.
                        </h1>
                        <p class="text-lg text-[var(--ink-soft)] leading-relaxed max-w-xl mb-10">
                            Dot.Design is where projects turn into graphics, social posts, and marketing collateral — a page-based canvas editor with generative AI on tap, backed by the same token and component library the rest of the Dot Ecosystem's interfaces are built from.
                        </p>

                        @guest
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--periwinkle)] text-white font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                                <a href="#canvas" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--ink)] font-medium rounded-full border border-[var(--line)] hover:border-[var(--periwinkle-soft)] transition-colors">
                                    See what it does
                                </a>
                            </div>
                        @endguest
                    </div>

                    <!-- Signature element: a canvas/artboard card carrying a hand-drawn bezier path, echoing the pen-tool anchor points and handles in the real Dot.Design logo mark -->
                    <div class="reveal" data-reveal>
                        <div class="relative rounded-[2rem] border border-[var(--line)] bg-[var(--panel)] p-6 sm:p-8 shadow-[0_30px_60px_-30px_rgba(34,31,43,0.25)]">
                            <div class="flex items-center justify-between mb-6 font-mono text-[11px] tracking-[0.12em] uppercase text-[var(--ink-soft)]">
                                <span>Canvas · Page 1</span>
                                <span>820 × 1024</span>
                            </div>

                            <svg viewBox="0 0 400 320" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto" aria-hidden="true">
                                <rect x="0.5" y="0.5" width="399" height="319" rx="14" stroke="var(--line)" stroke-dasharray="4 4"/>
                                <path
                                    class="draw-path"
                                    data-reveal
                                    d="M40 240 C 90 60, 160 60, 200 160 C 240 260, 310 260, 360 100"
                                    stroke="var(--periwinkle)"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                />
                                <line x1="40" y1="240" x2="90" y2="60" stroke="var(--ink-soft)" stroke-width="1" opacity="0.35"/>
                                <line x1="200" y1="160" x2="160" y2="60" stroke="var(--ink-soft)" stroke-width="1" opacity="0.35"/>
                                <line x1="200" y1="160" x2="240" y2="260" stroke="var(--ink-soft)" stroke-width="1" opacity="0.35"/>
                                <line x1="360" y1="100" x2="310" y2="260" stroke="var(--ink-soft)" stroke-width="1" opacity="0.35"/>

                                <rect x="34" y="234" width="12" height="12" rx="2" fill="var(--gold-bright)"/>
                                <rect x="354" y="94" width="12" height="12" rx="2" fill="var(--gold-bright)"/>
                                <circle cx="90" cy="60" r="5" fill="var(--panel)" stroke="var(--periwinkle)" stroke-width="2"/>
                                <circle cx="160" cy="60" r="5" fill="var(--panel)" stroke="var(--periwinkle)" stroke-width="2"/>
                                <circle cx="240" cy="260" r="5" fill="var(--panel)" stroke="var(--periwinkle)" stroke-width="2"/>
                                <circle cx="310" cy="260" r="5" fill="var(--panel)" stroke="var(--periwinkle)" stroke-width="2"/>
                                <circle cx="200" cy="160" r="6" fill="var(--ink)"/>
                            </svg>

                            <div class="flex items-center gap-3 mt-6 pt-6 border-t border-[var(--line)]">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-5 h-5 rounded-full border border-[var(--line)]" style="background: var(--gold-bright)"></span>
                                    <span class="w-5 h-5 rounded-full border border-[var(--line)]" style="background: var(--periwinkle-soft)"></span>
                                    <span class="w-5 h-5 rounded-full border border-[var(--line)]" style="background: var(--ink)"></span>
                                </div>
                                <p class="font-mono text-[11px] tracking-wide text-[var(--ink-soft)]">Token set · Core Palette v1</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Two systems -->
        <section id="canvas" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-2xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">What it's built from</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Two systems that share one platform, on purpose
                    </h2>
                    <p class="text-[var(--ink-soft)] leading-relaxed mt-4 max-w-xl">
                        A creation tool for end users, and a token &amp; component library other Dot platforms consume — different tables, different tenancy rules, no shared foreign keys.
                    </p>
                </div>

                <div class="grid lg:grid-cols-2 gap-x-16">
                    <div>
                        <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-1">Canvas &amp; creation</h3>
                        <p class="text-sm text-[var(--ink-soft)] mb-6">Per-user. What you actually open and edit.</p>
                        <div class="border-t border-[var(--line)]">
                            @php
                                $canvasItems = [
                                    ['tag' => 'Projects', 'title' => 'Design projects', 'body' => 'A canvas project with a type — graphic, social, print — sized to a width, height, and unit of your choosing.'],
                                    ['tag' => 'Canvases', 'title' => 'Multi-page canvases', 'body' => 'Each project holds one or more ordered pages, with elements, background color, and background image tracked per page.'],
                                    ['tag' => 'Assets', 'title' => 'Design assets', 'body' => 'Uploaded or generated files — type, file path, size, and freeform metadata kept alongside the project.'],
                                    ['tag' => 'AI', 'title' => 'AI generation log', 'body' => 'Every AI request recorded — prompt, result, provider, and token usage — across Anthropic, OpenAI, Stability, and Replicate.'],
                                ];
                            @endphp
                            @foreach ($canvasItems as $item)
                                <div class="row-hover border-b border-[var(--line)] px-1 py-6 transition-colors reveal" data-reveal>
                                    <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--periwinkle)] mb-2">{{ $item['tag'] }}</p>
                                    <h4 class="font-display font-semibold text-lg text-[var(--ink)] mb-1.5">{{ $item['title'] }}</h4>
                                    <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $item['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div id="system" class="mt-14 lg:mt-0">
                        <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-1">Design system</h3>
                        <p class="text-sm text-[var(--ink-soft)] mb-6">Shared. The same catalog every team and platform draws from.</p>
                        <div class="border-t border-[var(--line)]">
                            @php
                                $systemItems = [
                                    ['tag' => 'Sets', 'title' => 'Token sets', 'body' => 'A versioned group of tokens — colors, type, spacing, motion — that version together as a unit.'],
                                    ['tag' => 'Tokens', 'title' => 'Design tokens', 'body' => 'Individual values inside a set, each with its own version, addressable by slug within that set.'],
                                    ['tag' => 'Components', 'title' => 'Components', 'body' => 'Reusable UI definitions, including Brain-surface components like the confidence badge and intent label.'],
                                    ['tag' => 'Tracking', 'title' => 'Consumption records', 'body' => 'Which platform consumes which token set, pinned to which version — one row per platform, per set.'],
                                ];
                            @endphp
                            @foreach ($systemItems as $item)
                                <div class="row-hover border-b border-[var(--line)] px-1 py-6 transition-colors reveal" data-reveal>
                                    <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold)] mb-2">{{ $item['tag'] }}</p>
                                    <h4 class="font-display font-semibold text-lg text-[var(--ink)] mb-1.5">{{ $item['title'] }}</h4>
                                    <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $item['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Ecosystem -->
        <section class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-bright)] mb-4">Part of the Dot Ecosystem</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-white leading-tight mb-5">
                            One account carries you into every Dot platform
                        </h2>
                        <p class="text-[#c9c5d8] leading-relaxed max-w-sm">
                            Dot.Design authenticates ecosystem users through a shared-token handoff, not a separate signup — the same pattern the rest of the Dot platforms use to link accounts together.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Team-based accounts', 'body' => 'Jetstream teams, invitations, and two-factor auth — the same account model across every project you open.'],
                                ['title' => 'Multi-provider AI', 'body' => 'Generate imagery through Anthropic, OpenAI, Stability, or Replicate, logged per request so usage stays visible.'],
                                ['title' => 'Ecosystem single sign-on', 'body' => 'A one-time Sanctum token minted elsewhere in the ecosystem logs you straight into your dashboard.'],
                                ['title' => 'Shared PostgreSQL', 'body' => 'Runs on the same database infrastructure as the rest of the Dot Ecosystem, ready for cross-platform data.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[rgba(255,255,255,0.14)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-white mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[#c9c5d8] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                    Bring your team's first project in
                </h2>
                <p class="text-[var(--ink-soft)] leading-relaxed mb-10 max-w-lg mx-auto">
                    No install, no separate design-system project to stand up first. Sign in with your Dot Ecosystem account or create one to get started.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--ink)] hover:bg-[var(--periwinkle)] text-white font-display font-semibold rounded-full transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--ink)] font-medium rounded-full border border-[var(--line)] hover:border-[var(--periwinkle-soft)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Design" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Design. Visual creation platform for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
