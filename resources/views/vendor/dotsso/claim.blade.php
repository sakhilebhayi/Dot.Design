{{-- R-54: the claim page, in The Light Table. Shown when an account here already uses the email address of the InfoDot sign-in.
     Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout title="Confirm it is you">
    <x-authentication-card>
        <div style="display: grid; gap: var(--lt-space-2);">
            <h1 class="lt-auth__title">Confirm it is you</h1>
            <p class="lt-small">An account here already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your designs, brand kits and teams stay exactly as they are.</p>
        </div>

        @if (session('dotsso_status'))
            <div class="lt-alert lt-alert--ok" role="status"><x-ds.icon name="circle-check" /><div>{{ session('dotsso_status') }}</div></div>
        @endif

        <form method="POST" action="{{ $verifyUrl }}" class="lt-form" novalidate>
            @csrf
            <div aria-live="polite">
                <x-ds.field label="Code from the email" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                    maxlength="{{ $codeLength }}" required autofocus class="lt-claim__code"
                    hint="It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries." />
            </div>
            <div class="lt-form__actions">
                <x-ds.button type="submit">Confirm and continue</x-ds.button>
            </div>
        </form>

        <div class="lt-form__actions" style="justify-content: space-between;">
            <form method="POST" action="{{ $resendUrl }}">@csrf<x-ds.button type="submit" variant="ghost" size="sm">Send a new code</x-ds.button></form>
            <form method="POST" action="{{ $cancelUrl }}">@csrf<x-ds.button type="submit" variant="ghost" size="sm">Cancel</x-ds.button></form>
        </div>
    </x-authentication-card>
</x-guest-layout>
