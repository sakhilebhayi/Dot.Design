<x-guest-layout :title="__('Reset password')">
    <x-authentication-card>
        <div style="display: grid; gap: var(--lt-space-2);">
            <h1 class="lt-auth__title">{{ __('Reset your password') }}</h1>
            <p class="lt-small">{{ __('Enter your email address and we will send you a link to choose a new password.') }}</p>
        </div>

        @session('status')
            <div class="lt-alert lt-alert--ok" role="status"><x-ds.icon name="circle-check" /><div>{{ $value }}</div></div>
        @endsession
        <x-validation-errors />

        <form method="POST" action="{{ route('password.email') }}" class="lt-form">
            @csrf
            <x-ds.field :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
            <div class="lt-form__actions">
                <x-ds.button type="submit">{{ __('Email me a reset link') }}</x-ds.button>
                <x-ds.button :href="route('login')" variant="ghost">{{ __('Back to log in') }}</x-ds.button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
