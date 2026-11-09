<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an oversized body with a 413 and a plain-language message before any of it is parsed or validated.
 * Usage: LimitRequestBody::class.':1400000' (bytes); without a parameter the page-save ceiling from config is used.
 * The web server's own limit (client_max_body_size / LimitRequestBody / post_max_size) must be at least this large:
 * see docs/R45_BACKEND.md.
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, ?string $bytes = null): Response
    {
        $limit = (int) ($bytes ?? config('design.studio.max_request_bytes'));
        $declared = (int) $request->headers->get('Content-Length', 0);

        if ($declared > $limit || strlen($request->getContent()) > $limit) {
            return response()->json([
                'status' => 'too_large',
                'message' => 'This is too much to send at once ('.round($limit / 1024).' KB limit). Remove some content and try again.',
            ], 413);
        }

        return $next($request);
    }
}
