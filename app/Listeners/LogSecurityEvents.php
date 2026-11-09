<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * The few account-security events an investigator needs after an incident (docs/R45_PRIVACY.md, "Breach response"): who signed in,
 * failed or was locked out, and when a password or second factor changed. One line each, `security.*`, with the person's id and a
 * keyed hash of the address (so repeated activity from one address can be correlated without the address being written down).
 * Never an email, a password, a token or a raw address. Rotates with the log channel (14 days with LOG_STACK=daily).
 */
class LogSecurityEvents
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            PasswordReset::class => 'passwordReset',
            PasswordUpdatedViaController::class => 'passwordChanged',
            TwoFactorAuthenticationEnabled::class => 'twoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            RecoveryCodesGenerated::class => 'recoveryCodes',
        ];
    }

    public function login(Login $event): void
    {
        $this->write('security.login', $event->user?->getAuthIdentifier());
    }

    public function failed(Failed $event): void
    {
        // Whether the account exists is recorded for investigators; the email that was typed is not.
        $this->write('security.login_failed', $event->user?->getAuthIdentifier(), ['known_account' => $event->user !== null]);
    }

    public function lockout(Lockout $event): void
    {
        $this->write('security.lockout', null);
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->write('security.password_reset', $event->user->getAuthIdentifier());
    }

    public function passwordChanged(PasswordUpdatedViaController $event): void
    {
        $this->write('security.password_changed', $event->user->getAuthIdentifier());
    }

    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->write('security.two_factor_enabled', $event->user->getAuthIdentifier());
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->write('security.two_factor_disabled', $event->user->getAuthIdentifier());
    }

    public function recoveryCodes(RecoveryCodesGenerated $event): void
    {
        $this->write('security.recovery_codes_regenerated', $event->user->getAuthIdentifier());
    }

    /** @param array<string, mixed> $extra */
    private function write(string $message, mixed $userId, array $extra = []): void
    {
        $address = request()->ip();

        Log::info($message, array_filter([
            'user_id' => $userId,
            'network' => $address === null ? null : substr(hash_hmac('sha256', $address, (string) config('app.key')), 0, 16),
        ] + $extra, fn ($v) => $v !== null));
    }
}
