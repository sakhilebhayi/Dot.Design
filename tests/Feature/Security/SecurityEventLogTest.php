<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security, part 8: breach-response hooks. The lines an investigator needs exist, carry the person's id and a keyed hash
 * of the address, and never an email, a password, a token or a raw address.
 */
class SecurityEventLogTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    public function test_sign_in_failure_lockout_and_credential_changes_are_logged_without_personal_detail(): void
    {
        Log::spy();
        $user = $this->person();
        $user->forceFill(['password' => bcrypt('a-long-test-password-123')])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->post('/login', ['email' => $user->email, 'password' => 'wrong-password-123']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->post('/login', ['email' => $user->email, 'password' => 'a-long-test-password-123']);

        $expectedNetwork = substr(hash_hmac('sha256', '198.51.100.77', (string) config('app.key')), 0, 16);

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'security.login_failed' && ($c['user_id'] ?? null) === $user->id && $c['known_account'] === true && $c['network'] === $expectedNetwork)->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'security.login_failed' && ! isset($c['user_id']) && $c['known_account'] === false)->once();
        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'security.login' && ($c['user_id'] ?? null) === $user->id)->once();

        // Nothing that was logged may carry an email, a password or the raw address.
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($user) {
            $line = $message.json_encode($context);

            return ! str_contains($line, $user->email) && ! str_contains($line, 'nobody@example.test') && ! str_contains($line, 'password') && ! str_contains($line, '198.51.100.77');
        })->atLeast()->times(3);
    }

    public function test_deleting_an_account_leaves_one_line_with_the_id_only(): void
    {
        Log::spy();
        $user = $this->person();
        $id = $user->id;
        $email = $user->email;

        app(DeletesUsers::class)->delete($user);

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'security.account_deleted' && $c === ['user_id' => $id] && ! str_contains(json_encode($c), $email))->once();
    }
}
