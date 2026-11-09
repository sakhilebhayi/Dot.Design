<x-guest-layout :title="__('Confirm password')">
    <x-authentication-card>
        <div style="display: grid; gap: var(--lt-space-2);">
            <h1 class="lt-auth__title">{{ __('Confirm your password') }}</h1>
            <p class="lt-small">{{ __('This is a secure area. Please confirm your password before continuing.') }}</p>
        </div>
        <x-validation-errors />

        <form method="POST" action="{{ route('password.confirm') }}" class="lt-form">
            @csrf
            <x-ds.field :label="__('Password')" name="password" type="password" required autofocus autocomplete="current-password" />
            <div class="lt-form__actions"><x-ds.button type="submit">{{ __('Confirm') }}</x-ds.button></div>
        </form>
    </x-authentication-card>
</x-guest-layout>
