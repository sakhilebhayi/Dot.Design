<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $plainToken = $request->query('token');

        // A missing, empty or non-string (e.g. token[]=x) value is a plain
        // 403, never a 500.
        abort_unless(is_string($plainToken) && $plainToken !== '', 403);

        $accessToken = PersonalAccessToken::findToken($plainToken);

        abort_if(
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast()),
            403
        );

        $user = $accessToken->tokenable;

        // Single use, even when the owner no longer exists.
        $accessToken->delete();

        abort_unless($user instanceof User, 403);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
