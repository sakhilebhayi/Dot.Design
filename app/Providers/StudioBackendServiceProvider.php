<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-45 backend: rate limiters for the studio API. They live here, not in a route file, so route:cache works.
 * Signed-in limiters are per user; the public share-link limiters are per link and visitor, and the limiter only
 * keeps a hash of the address for the length of the window.
 */
class StudioBackendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->perUser('design-saves', 'saves_per_minute', 'You are saving too quickly. Wait a few seconds and it will continue.');
        $this->perUser('design-shares', 'shares_per_minute', 'Too many share-link changes. Wait a minute and try again.');
        $this->perUser('design-comments', 'comments_per_minute', 'You are commenting too quickly. Wait a minute and try again.');
        $this->perUser('design-thumbnails', 'thumbnails_per_minute', 'Thumbnails are being sent too quickly. Try again in a minute.');
        $this->perUser('design-exports', 'exports_per_minute', 'Too many exports recorded. Try again in a minute.');

        RateLimiter::for('design-public-view', fn (Request $request) => Limit::perMinute((int) config('design.studio.public_views_per_minute'))
            ->by('v:'.substr((string) $request->route('token'), 0, 12).'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->view('design.share-unavailable', [], 429, $headers)));

        RateLimiter::for('design-public-comments', fn (Request $request) => Limit::perMinute((int) config('design.studio.public_comments_per_minute'))
            ->by('c:'.substr((string) $request->route('token'), 0, 12).'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => 'You are commenting too quickly. Wait a minute and try again.',
            ], 429, $headers)));
    }

    private function perUser(string $name, string $configKey, string $message): void
    {
        RateLimiter::for($name, fn (Request $request) => Limit::perMinute((int) config("design.studio.{$configKey}"))
            ->by($request->user()?->id ?: $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['status' => 'throttled', 'message' => $message], 429, $headers)));
    }
}
