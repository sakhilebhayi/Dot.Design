<?php

namespace App\Providers;

use App\Services\Design\Assistant\AnthropicClient;
use App\Services\Design\Assistant\Contracts\ModelClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-45 assistant wiring: the model client binding (tests bind a fake of ModelClient) and the per-minute limiter.
 * The daily caps are counted from design_ai_proposals (App\Services\Design\Assistant\UsageGuard), not here.
 */
class StudioAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ModelClient::class, AnthropicClient::class);
    }

    public function boot(): void
    {
        RateLimiter::for('design-ai', fn (Request $request) => Limit::perMinute((int) config('design_ai.limits.per_minute'))
            ->by('ai:'.($request->user()?->id ?: $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json([
                'status' => 'throttled',
                'message' => 'You are asking the assistant too quickly. Wait a minute and try again.',
            ], 429, $headers)));
    }
}
