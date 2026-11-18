{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout :title="$headline">
    <x-authentication-card>
        <div style="display: grid; gap: var(--lt-space-2);">
            <h1 class="lt-auth__title">{{ $headline }}</h1>
        </div>

        <div class="lt-alert lt-alert--bad" role="alert"><x-ds.icon name="triangle-alert" /><div>{{ $body }}</div></div>

        <div class="lt-form__actions">
            @if ($retry)<x-ds.button :href="$retryUrl">Start again</x-ds.button>@endif
            <x-ds.button :href="$homeUrl" variant="secondary">Back to Dot.Design</x-ds.button>
        </div>
        <p class="lt-small">Reference: {{ $code }}</p>
    </x-authentication-card>
</x-guest-layout>
