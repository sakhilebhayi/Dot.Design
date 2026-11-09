<x-guest-layout :title="__('Create account')">
    <x-authentication-card>
        <h1 class="lt-auth__title">{{ __('Create your account') }}</h1>
        <x-validation-errors />

        <form method="POST" action="{{ route('register') }}" class="lt-form">
            @csrf
            <x-ds.field :label="__('Name')" name="name" type="text" required autofocus autocomplete="name" />
            <x-ds.field :label="__('Email')" name="email" type="email" required autocomplete="username" />
            <x-ds.field :label="__('Password')" name="password" type="password" required autocomplete="new-password" />
            <x-ds.field :label="__('Confirm password')" name="password_confirmation" type="password" required autocomplete="new-password" />

            @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
                <label for="terms" class="lt-check" style="align-items: flex-start;">
                    <input type="checkbox" name="terms" id="terms" required>
                    <span>{!! __('I agree to the :terms_of_service and :privacy_policy', [
                        'terms_of_service' => '<a target="_blank" href="'.route('terms.show').'">'.__('Terms of Service').'</a>',
                        'privacy_policy' => '<a target="_blank" href="'.route('policy.show').'">'.__('Privacy Policy').'</a>',
                    ]) !!}</span>
                </label>
            @endif

            <div class="lt-form__actions" style="justify-content: space-between;">
                <x-ds.button type="submit">{{ __('Create account') }}</x-ds.button>
                <a href="{{ route('login') }}" class="lt-small">{{ __('Already have an account?') }}</a>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
