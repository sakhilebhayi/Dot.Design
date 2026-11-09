<x-guest-layout :title="'Privacy'">
    <main id="main" class="lt-page lt-page--narrow" tabindex="-1" style="padding-top: var(--lt-space-5);">
        <a href="{{ url('/') }}" class="lt-brand" aria-label="Dot.Design, home" style="padding-left: 0; margin-bottom: var(--lt-space-6);">
            <span class="lt-mark" role="img" aria-label="Dot.Design mark"></span>
            <span class="lt-brand__name">Dot.Design</span>
        </a>
        <article class="lt-prose prose">
            <h1 class="lt-h1" style="margin-bottom: var(--lt-space-5);">Privacy policy</h1>
            {!! $policy !!}
        </article>
    </main>
</x-guest-layout>
