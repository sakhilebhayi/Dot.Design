<?php

namespace App\Http\Middleware\Studio;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The studio business endpoints only speak JSON; validation and authorisation errors must come back as JSON too. */
class AcceptsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
