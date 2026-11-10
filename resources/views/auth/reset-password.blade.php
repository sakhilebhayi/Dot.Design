<x-guest-layout :title="__('Choose a new password')">
    <x-authentication-card>
        <h1 class="lt-auth__title">{{ __('Choose a new password') }}</h1>
        <x-validation-errors />

        <form method="POST" action="{{ route('password.update') }}" class="lt-form">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <x-ds.field :label="__('Email')" name="email" type="email" :value="$request->email" required autofocus autocomplete="username" />
            <x-ds.field :label="__('New password')" name="password" type="password" required autocomplete="new-password" />
            <x-ds.field :label="__('Confirm new password')" name="password_confirmation" type="password" required autocomplete="new-password" />
            <div class="lt-form__actions"><x-ds.button type="submit">{{ __('Save new password') }}</x-ds.button></div>
        </form>
    </x-authentication-card>
</x-guest-layout>
