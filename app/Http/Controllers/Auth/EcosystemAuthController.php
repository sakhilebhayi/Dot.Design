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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EcosystemAuthController extends Controller
{
    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is still understood for
     * links minted before this change, and is spent exactly the same way.
     */
    public function handle(Request $request): RedirectResponse
    {
        if ($request->isMethod('post') && ! $this->postedFromInfoDot($request)) {
            return $this->refused();
        }

        try {
            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (HttpExceptionInterface $e) {
            return $this->refused($e->getStatusCode() === 503);
        }
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function postedFromInfoDot(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
        }

        $host = strtolower($host);
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        return $host === $self || $host === $parent || str_ends_with($host, '.'.$parent);
    }

    private function refused(bool $unavailable = false): RedirectResponse
    {
        return redirect('/login', 303)->with('status', $unavailable
            ? 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.'
            : 'We could not sign you in through InfoDot. Please open this app again from InfoDot.');
    }

    public function redeem(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $plainToken = $request->input('token');

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

        return redirect($this->safePath($request->input('redirect')) ?? route('dashboard'));
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
