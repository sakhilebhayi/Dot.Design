<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $plainToken = $request->query('token');

        // A missing, empty or non-string (e.g. token[]=x) value is a plain
        // 403, never a 500.
        abort_unless(is_string($plainToken) && $plainToken !== '', 403);

        $accessToken = PersonalAccessToken::findToken($plainToken);

        if ($accessToken) {
            // A token minted in a database we share (the original design): checked,
            // spent, and decisive either way. A token we know but refuse is never
            // passed on to InfoDot.
            $user = $this->userFromLocalToken($accessToken);
        } elseif ($infoDot->enabled()) {
            // Production gives each Dot app its own database, so InfoDot's token is
            // not in ours: ask InfoDot to prove it.
            $user = $this->userFromInfoDot($plainToken, $infoDot, $provision, $syncTeams);
        } else {
            $user = null;
        }

        abort_unless($user instanceof User, 403);

        Auth::login($user);

        // New session id on privilege change (prevents session fixation).
        $request->session()->regenerate();

        return redirect($this->safePath($request->query('redirect')) ?? route('dashboard'));
    }

    private function userFromLocalToken(PersonalAccessToken $accessToken): ?User
    {
        $valid = $accessToken->can('ecosystem:read')
            && ! ($accessToken->expires_at && $accessToken->expires_at->isPast());

        $user = $valid ? $accessToken->tokenable : null;

        // Single use, even when the owner no longer exists.
        if ($valid) {
            $accessToken->delete();
        }

        return $user instanceof User ? $user : null;
    }

    private function userFromInfoDot(string $token, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): ?User
    {
        try {
            $identity = $infoDot->redeem($token);
        } catch (InfoDotUnavailable) {
            abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
        }

        $user = $identity ? $provision($identity) : null;

        if ($user && $identity) {
            // This app scopes its data per person, so teams are synced for consistency
            // with the rest of the ecosystem and never decide what a person can see.
            $syncTeams($user, $identity);
        }

        return $user;
    }

    /**
     * A deep link from InfoDot may name the page to open. Only a path inside this app is
     * honoured: one leading slash, no `//`, no backslashes, no `..`, no control characters,
     * so it can never be turned into an open redirect.
     */
    private function safePath(mixed $path): ?string
    {
        if (! is_string($path) || ! preg_match('#^/(?!/)[^\\\\\x00-\x20\x7f]*$#', $path)) {
            return null;
        }

        if (str_contains($path, '..') || str_contains(strtolower($path), '%2e') || str_contains(strtolower($path), '%5c')) {
            return null;
        }

        return $path;
    }
}
