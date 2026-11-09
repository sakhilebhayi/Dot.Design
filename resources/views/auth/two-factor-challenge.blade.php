<x-guest-layout :title="__('Two-factor check')">
    <x-authentication-card>
        <div x-data="{ recovery: false }" style="display: grid; gap: var(--lt-space-5);">
            <div style="display: grid; gap: var(--lt-space-2);">
                <h1 class="lt-auth__title">{{ __('One more check') }}</h1>
                <p class="lt-small" x-show="! recovery">{{ __('Enter the code from your authenticator app.') }}</p>
                <p class="lt-small" x-cloak x-show="recovery">{{ __('Enter one of your emergency recovery codes.') }}</p>
            </div>

            <x-validation-errors />

            <form method="POST" action="{{ route('two-factor.login') }}" class="lt-form">
                @csrf
                <div x-show="! recovery"><x-ds.field :label="__('Code')" name="code" type="text" inputmode="numeric" autofocus x-ref="code" autocomplete="one-time-code" /></div>
                <div x-cloak x-show="recovery"><x-ds.field :label="__('Recovery code')" name="recovery_code" type="text" x-ref="recovery_code" autocomplete="one-time-code" /></div>

                <div class="lt-form__actions" style="justify-content: space-between;">
                    <x-ds.button type="submit">{{ __('Log in') }}</x-ds.button>
                    <button type="button" class="lt-small" style="text-decoration: underline;" x-show="! recovery" x-on:click="recovery = true; $nextTick(() => { $refs.recovery_code.focus() })">{{ __('Use a recovery code') }}</button>
                    <button type="button" class="lt-small" style="text-decoration: underline;" x-cloak x-show="recovery" x-on:click="recovery = false; $nextTick(() => { $refs.code.focus() })">{{ __('Use an authentication code') }}</button>
                </div>
            </form>
        </div>
    </x-authentication-card>
</x-guest-layout>
