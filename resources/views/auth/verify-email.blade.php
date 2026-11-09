<x-guest-layout :title="__('Verify your email')">
    <x-authentication-card>
        <div style="display: grid; gap: var(--lt-space-2);">
            <h1 class="lt-auth__title">{{ __('Check your email') }}</h1>
            <p class="lt-small">{{ __('We sent a verification link to the address on your profile. Open it to continue. If it did not arrive, we can send another.') }}</p>
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="lt-alert lt-alert--ok" role="status"><x-ds.icon name="circle-check" /><div>{{ __('A new verification link has been sent to the email address on your profile.') }}</div></div>
        @endif

        <div class="lt-form__actions" style="justify-content: space-between;">
            <form method="POST" action="{{ route('verification.send') }}">@csrf<x-ds.button type="submit">{{ __('Send the link again') }}</x-ds.button></form>
            <div style="display: flex; gap: var(--lt-space-4); align-items: center;">
                <a href="{{ route('profile.show') }}" class="lt-small">{{ __('Edit profile') }}</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="lt-small" style="text-decoration: underline;">{{ __('Log out') }}</button></form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
