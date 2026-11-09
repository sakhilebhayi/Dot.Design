<?php

namespace App\Providers;

use App\Contracts\Design\ActivityRecorder;
use App\Contracts\Design\VersionSnapshotter;
use App\Services\Design\NullActivityRecorder;
use App\Services\Design\NullVersionSnapshotter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-45 business agent wiring: the seams other agents replace (version snapshots, activity log) and the
 * limiter for the endpoints that create things. bindIf means a real binding registered by another
 * provider is never overwritten by these null defaults, whatever the provider order.
 */
class StudioBusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(VersionSnapshotter::class, NullVersionSnapshotter::class);
        $this->app->bindIf(ActivityRecorder::class, NullActivityRecorder::class);
    }

    public function boot(): void
    {
        RateLimiter::for('design-briefs', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many requests. Wait a minute and try again.',
            ], 429, $headers)));
    }
}
