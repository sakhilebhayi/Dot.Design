<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The studio's JSON endpoints always answer JSON, even when a client forgets the Accept header: validation failures
 * come back as 422 JSON (not an HTML redirect), a missing row as a JSON 404, a forbidden action as a JSON 403.
 */
class JsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
