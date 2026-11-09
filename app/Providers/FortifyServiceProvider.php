<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Three layers (R-45 security S-9): one address guessing one account (the original rule); one address spraying many accounts;
        // and many addresses taking turns at one account. The last is hourly and generous so a stranger cannot lock a person out.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute((int) config('security.login_per_ip_per_minute', 20))->by('login-ip:'.$request->ip()),
                Limit::perHour((int) config('security.login_per_account_per_hour', 60))->by('login-account:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return [
                Limit::perMinute(5)->by((string) $request->session()->get('login.id')),
                Limit::perMinute(20)->by('2fa-ip:'.$request->ip()),
            ];
        });
    }
}
