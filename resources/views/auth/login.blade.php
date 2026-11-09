<x-guest-layout :title="__('Log in')">
    <x-authentication-card>
        <div style="display: grid; gap: var(--lt-space-2);">
            <h1 class="lt-auth__title">{{ __('Log in') }}</h1>
            <p class="lt-small">{{ __('Your designs are where you left them.') }}</p>
        </div>

        <x-validation-errors />

        @session('status')
            <div class="lt-alert lt-alert--ok" role="status"><x-ds.icon name="circle-check" /><div>{{ $value }}</div></div>
        @endsession

        <form method="POST" action="{{ route('login') }}" class="lt-form">
            @csrf
            <x-ds.field :label="__('Email')" name="email" type="email" required autofocus autocomplete="username" />
            <x-ds.field :label="__('Password')" name="password" type="password" required autocomplete="current-password" />
            <label for="remember_me" class="lt-check"><input id="remember_me" type="checkbox" name="remember"> <span>{{ __('Keep me logged in on this device') }}</span></label>
            <div class="lt-form__actions" style="justify-content: space-between;">
                <x-ds.button type="submit">{{ __('Log in') }}</x-ds.button>
                @if (Route::has('password.request'))<a href="{{ route('password.request') }}" class="lt-small">{{ __('Forgot your password?') }}</a>@endif
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
