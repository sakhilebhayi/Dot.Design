<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in studio (everything under /design, the dashboard and the design-system pages) is a browser application that
 * authenticates with the session cookie. It never takes a Sanctum bearer token: a token carries abilities (read, create,
 * update, delete) that these routes do not check, so accepting one would let a token with no abilities read everything its
 * owner can. Only /api/* takes tokens, and checks their abilities there.
 */
class RefuseBearerTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            abort(401, 'This page needs a signed-in browser session, not an API token.');
        }

        return $next($request);
    }
}
